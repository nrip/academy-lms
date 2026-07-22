<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\TokenConfirmationService;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Security\ConfirmationCookieSettings;
use Academy\Http\Security\TokenPageHeaderPolicy;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Testing-only probe surface for scanner-safe verify/reset confirmation mechanics.
 * Registered only when APP_ENV=testing.
 */
final class Wp01b2aTokenProbeController
{
    public function __construct(
        private readonly TokenConfirmationService $confirmations,
        private readonly VerificationTokenIssuer $issuer,
        private readonly ConfirmationCookieSettings $cookies,
        private readonly TokenPageHeaderPolicy $tokenHeaders,
        private readonly PhpRenderer $renderer,
        private readonly int $contextTtlSeconds,
    ) {
    }

    public function verifyEmailGet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleTokenGet($request, TokenPurpose::EMAIL_VERIFY, '/verify-email');
    }

    public function resetPasswordGet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleTokenGet($request, TokenPurpose::PASSWORD_RESET, '/reset-password');
    }

    public function verifyEmailConfirmGet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderConfirmPage($request, TokenPurpose::EMAIL_VERIFY, '/verify-email/confirm');
    }

    public function resetPasswordConfirmGet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderConfirmPage($request, TokenPurpose::PASSWORD_RESET, '/reset-password/confirm');
    }

    public function verifyEmailConfirmPost(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleConfirmPost($request, TokenPurpose::EMAIL_VERIFY);
    }

    public function resetPasswordConfirmPost(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleConfirmPost($request, TokenPurpose::PASSWORD_RESET);
    }

    public function verifyEmailResult(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderResult($request, TokenPurpose::EMAIL_VERIFY);
    }

    public function resetPasswordResult(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderResult($request, TokenPurpose::PASSWORD_RESET);
    }

    /**
     * Testing helper: issue a token without registration UX.
     */
    public function issueProbe(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $userId = (int) ($body['user_id'] ?? 0);
        $purpose = (string) ($body['purpose'] ?? TokenPurpose::EMAIL_VERIFY);
        $email = (string) ($body['email'] ?? 'probe@example.test');
        $expires = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));
        $issued = $this->issuer->issue($userId, $purpose, $email, $expires);

        return new JsonResponse([
            'verification_token_id' => $issued['verification_token_id'],
            'raw_token' => $issued['raw_token'],
        ]);
    }

    private function handleTokenGet(
        ServerRequestInterface $request,
        string $purpose,
        string $basePath,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $rawToken = isset($params['token']) && is_string($params['token']) ? $params['token'] : '';
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!is_string($clientIp) || $clientIp === '') {
            $clientIp = '0.0.0.0';
        }

        try {
            $result = $this->confirmations->beginConfirmationFromRawToken(
                $rawToken,
                $purpose,
                $clientIp,
                $this->contextTtlSeconds,
            );
        } catch (RateLimitExceededException $exception) {
            $response = new HtmlResponse('Too many requests.', 429);
            $response = $response->withHeader('Retry-After', (string) $exception->retryAfterSeconds());

            return $this->tokenHeaders->apply($response);
        }

        if ($result['status'] !== 'ok') {
            $response = new RedirectResponse($basePath . '/result?status=invalid_or_expired', 302);

            return $this->tokenHeaders->apply($response);
        }

        $secret = $result['confirmation_secret'] ?? '';
        $response = new RedirectResponse($basePath . '/confirm', 302);
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->cookies->buildSetCookie($purpose, $secret),
        );

        return $this->tokenHeaders->apply($response);
    }

    private function renderConfirmPage(
        ServerRequestInterface $request,
        string $purpose,
        string $formAction,
    ): ResponseInterface {
        $cookieName = $this->cookies->cookieNameForPurpose($purpose);
        $cookies = $request->getCookieParams();
        $hasCookie = isset($cookies[$cookieName]) && is_string($cookies[$cookieName]) && $cookies[$cookieName] !== '';
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        $html = $this->renderer->render('pages/verify/confirm', [
            'title' => 'Confirm',
            'formAction' => $formAction,
            'csrf' => $csrf,
            'hasCookie' => $hasCookie,
            'purpose' => $purpose,
        ]);

        return $this->tokenHeaders->apply(new HtmlResponse($html));
    }

    private function handleConfirmPost(ServerRequestInterface $request, string $purpose): ResponseInterface
    {
        $cookieName = $this->cookies->cookieNameForPurpose($purpose);
        $cookies = $request->getCookieParams();
        $secret = isset($cookies[$cookieName]) && is_string($cookies[$cookieName])
            ? rawurldecode($cookies[$cookieName])
            : '';

        $base = $purpose === TokenPurpose::PASSWORD_RESET ? '/reset-password' : '/verify-email';
        $clear = $this->cookies->buildClearCookie($purpose);

        try {
            $this->confirmations->confirm($secret, $purpose);
            $response = new RedirectResponse($base . '/result?status=success', 302);
        } catch (DomainRuleException) {
            $response = new RedirectResponse($base . '/result?status=invalid_or_expired', 302);
        }

        $response = $response->withAddedHeader('Set-Cookie', $clear);

        return $this->tokenHeaders->apply($response);
    }

    private function renderResult(ServerRequestInterface $request, string $purpose): ResponseInterface
    {
        $status = (string) ($request->getQueryParams()['status'] ?? 'invalid_or_expired');
        if ($status !== 'success') {
            $status = 'invalid_or_expired';
        }

        $html = $this->renderer->render('pages/verify/result', [
            'title' => 'Result',
            'status' => $status,
            'purpose' => $purpose,
        ]);

        $response = $this->tokenHeaders->apply(new HtmlResponse($html));
        // Ensure cookie cleared on result surfaces after attempted submit flows.
        $response = $response->withAddedHeader('Set-Cookie', $this->cookies->buildClearCookie($purpose));

        return $response;
    }
}
