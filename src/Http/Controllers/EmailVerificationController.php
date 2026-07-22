<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\EmailVerificationResendService;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Security\ConfirmationCookieSettings;
use Academy\Http\Security\TokenPageHeaderPolicy;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class EmailVerificationController
{
    public function __construct(
        private readonly TokenConfirmationService $confirmations,
        private readonly EmailVerificationResendService $resendService,
        private readonly ConfirmationCookieSettings $cookies,
        private readonly TokenPageHeaderPolicy $tokenHeaders,
        private readonly PhpRenderer $renderer,
        private readonly int $contextTtlSeconds,
    ) {
    }

    public function verifyEmailGet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleTokenGet($request);
    }

    public function verifyEmailConfirmGet(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderConfirmPage($request);
    }

    public function verifyEmailConfirmPost(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handleConfirmPost($request);
    }

    public function verifyEmailResult(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderResult($request);
    }

    public function resendForm(ServerRequestInterface $request): ResponseInterface
    {
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
        $status = (string) ($request->getQueryParams()['status'] ?? '');
        $hasPendingMarker = $this->pendingUserIdFromSession($request) !== null;

        $html = $this->renderer->render('pages/verify/email_resend', [
            'title' => 'Resend verification email',
            'csrf' => $csrf,
            'hasPendingMarker' => $hasPendingMarker,
            'status' => $status,
        ]);

        return new HtmlResponse($html);
    }

    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $emailIdentifier = $email !== '' ? $email : null;

        $this->resendService->resend(
            $this->pendingUserIdFromSession($request),
            $emailIdentifier,
            $this->clientIp($request),
        );

        return new RedirectResponse('/verify-email/resend?status=sent', 302);
    }

    private function handleTokenGet(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $rawToken = isset($params['token']) && is_string($params['token']) ? $params['token'] : '';

        try {
            $result = $this->confirmations->beginConfirmationFromRawToken(
                $rawToken,
                TokenPurpose::EMAIL_VERIFY,
                $this->clientIp($request),
                $this->contextTtlSeconds,
            );
        } catch (RateLimitExceededException $exception) {
            $response = new HtmlResponse('Too many requests.', 429);
            $response = $response->withHeader('Retry-After', (string) $exception->retryAfterSeconds());

            return $this->tokenHeaders->apply($response);
        }

        if ($result['status'] !== 'ok') {
            $response = new RedirectResponse('/verify-email/result?status=invalid_or_expired', 302);

            return $this->tokenHeaders->apply($response);
        }

        $secret = $result['confirmation_secret'] ?? '';
        $response = new RedirectResponse('/verify-email/confirm', 302);
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->cookies->buildSetCookie(TokenPurpose::EMAIL_VERIFY, $secret),
        );

        return $this->tokenHeaders->apply($response);
    }

    private function renderConfirmPage(ServerRequestInterface $request): ResponseInterface
    {
        $cookieName = $this->cookies->cookieNameForPurpose(TokenPurpose::EMAIL_VERIFY);
        $cookies = $request->getCookieParams();
        $hasCookie = isset($cookies[$cookieName]) && is_string($cookies[$cookieName]) && $cookies[$cookieName] !== '';
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        $html = $this->renderer->render('pages/verify/confirm', [
            'title' => 'Confirm email',
            'formAction' => '/verify-email/confirm',
            'csrf' => $csrf,
            'hasCookie' => $hasCookie,
            'purpose' => TokenPurpose::EMAIL_VERIFY,
        ]);

        return $this->tokenHeaders->apply(new HtmlResponse($html));
    }

    private function handleConfirmPost(ServerRequestInterface $request): ResponseInterface
    {
        $cookieName = $this->cookies->cookieNameForPurpose(TokenPurpose::EMAIL_VERIFY);
        $cookies = $request->getCookieParams();
        $secret = isset($cookies[$cookieName]) && is_string($cookies[$cookieName])
            ? rawurldecode($cookies[$cookieName])
            : '';

        $clear = $this->cookies->buildClearCookie(TokenPurpose::EMAIL_VERIFY);

        try {
            $this->confirmations->confirm($secret, TokenPurpose::EMAIL_VERIFY);
            $response = new RedirectResponse('/verify-email/result?status=success', 302);
        } catch (DomainRuleException) {
            $response = new RedirectResponse('/verify-email/result?status=invalid_or_expired', 302);
        }

        $response = $response->withAddedHeader('Set-Cookie', $clear);

        return $this->tokenHeaders->apply($response);
    }

    private function renderResult(ServerRequestInterface $request): ResponseInterface
    {
        $status = (string) ($request->getQueryParams()['status'] ?? 'invalid_or_expired');
        if ($status !== 'success') {
            $status = 'invalid_or_expired';
        }

        $html = $this->renderer->render('pages/verify/result', [
            'title' => 'Email verification',
            'status' => $status,
            'purpose' => TokenPurpose::EMAIL_VERIFY,
        ]);

        $response = $this->tokenHeaders->apply(new HtmlResponse($html));
        $response = $response->withAddedHeader('Set-Cookie', $this->cookies->buildClearCookie(TokenPurpose::EMAIL_VERIFY));

        return $response;
    }

    private function pendingUserIdFromSession(ServerRequestInterface $request): ?int
    {
        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        if (!$session instanceof SessionRecord) {
            return null;
        }

        $id = $session->payload['pending_verification_user_id'] ?? null;
        if (is_int($id)) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return null;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!is_string($clientIp) || $clientIp === '') {
            return '0.0.0.0';
        }

        return $clientIp;
    }
}
