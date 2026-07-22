<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\PasswordResetService;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Security\ConfirmationCookieSettings;
use Academy\Http\Security\TokenPageHeaderPolicy;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasswordResetController
{
    public function __construct(
        private readonly TokenConfirmationService $confirmations,
        private readonly PasswordResetService $passwordReset,
        private readonly ConfirmationCookieSettings $cookies,
        private readonly TokenPageHeaderPolicy $tokenHeaders,
        private readonly PhpRenderer $renderer,
        private readonly int $contextTtlSeconds,
    ) {
    }

    public function resetPasswordGet(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $rawToken = isset($params['token']) && is_string($params['token']) ? $params['token'] : '';

        try {
            $result = $this->confirmations->beginConfirmationFromRawToken(
                $rawToken,
                TokenPurpose::PASSWORD_RESET,
                $this->clientIp($request),
                $this->contextTtlSeconds,
            );
        } catch (RateLimitExceededException $exception) {
            $response = new HtmlResponse('Too many requests.', 429);
            $response = $response->withHeader('Retry-After', (string) $exception->retryAfterSeconds());

            return $this->tokenHeaders->apply($response);
        }

        if ($result['status'] !== 'ok') {
            $response = new RedirectResponse('/reset-password/result?status=invalid_or_expired', 302);

            return $this->tokenHeaders->apply($response);
        }

        $secret = $result['confirmation_secret'] ?? '';
        $response = new RedirectResponse('/reset-password/confirm', 302);
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->cookies->buildSetCookie(TokenPurpose::PASSWORD_RESET, $secret),
        );

        return $this->tokenHeaders->apply($response);
    }

    public function confirmGet(ServerRequestInterface $request): ResponseInterface
    {
        $cookieName = $this->cookies->cookieNameForPurpose(TokenPurpose::PASSWORD_RESET);
        $cookies = $request->getCookieParams();
        $hasCookie = isset($cookies[$cookieName]) && is_string($cookies[$cookieName]) && $cookies[$cookieName] !== '';
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        $html = $this->renderer->render('pages/auth/reset_confirm', [
            'title' => 'Confirm password reset',
            'formAction' => '/reset-password/confirm',
            'csrf' => $csrf,
            'hasCookie' => $hasCookie,
        ]);

        return $this->tokenHeaders->apply(new HtmlResponse($html));
    }

    public function confirmPost(ServerRequestInterface $request): ResponseInterface
    {
        $cookieName = $this->cookies->cookieNameForPurpose(TokenPurpose::PASSWORD_RESET);
        $cookies = $request->getCookieParams();
        $secret = isset($cookies[$cookieName]) && is_string($cookies[$cookieName])
            ? rawurldecode($cookies[$cookieName])
            : '';

        $clearConfirm = $this->cookies->buildClearCookie(TokenPurpose::PASSWORD_RESET);

        try {
            $result = $this->passwordReset->confirm($secret);
            $response = new RedirectResponse('/reset-password/form', 302);
            $response = $response->withAddedHeader('Set-Cookie', $clearConfirm);
            $response = $response->withAddedHeader(
                'Set-Cookie',
                $this->cookies->buildResetAuthSetCookie($result['authorization_secret']),
            );
        } catch (DomainRuleException) {
            $response = new RedirectResponse('/reset-password/result?status=invalid_or_expired', 302);
            $response = $response->withAddedHeader('Set-Cookie', $clearConfirm);
            $response = $response->withAddedHeader('Set-Cookie', $this->cookies->buildResetAuthClearCookie());
        }

        return $this->tokenHeaders->apply($response);
    }

    public function formGet(ServerRequestInterface $request): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $hasAuth = isset($cookies[$this->cookies->resetAuthCookieName])
            && is_string($cookies[$this->cookies->resetAuthCookieName])
            && $cookies[$this->cookies->resetAuthCookieName] !== '';

        if (!$hasAuth) {
            $response = new RedirectResponse('/reset-password/result?status=invalid_or_expired', 302);

            return $this->tokenHeaders->apply($response);
        }

        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        $html = $this->renderer->render('pages/auth/reset_form', [
            'title' => 'Choose a new password',
            'csrf' => $csrf,
            'error' => null,
        ]);

        return $this->tokenHeaders->apply(new HtmlResponse($html));
    }

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $secret = isset($cookies[$this->cookies->resetAuthCookieName]) && is_string($cookies[$this->cookies->resetAuthCookieName])
            ? rawurldecode($cookies[$this->cookies->resetAuthCookieName])
            : '';

        $body = (array) $request->getParsedBody();
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $passwordConfirm = isset($body['password_confirm']) && is_string($body['password_confirm'])
            ? $body['password_confirm']
            : '';

        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        if ($password !== $passwordConfirm) {
            $html = $this->renderer->render('pages/auth/reset_form', [
                'title' => 'Choose a new password',
                'csrf' => $csrf,
                'error' => 'Passwords do not match.',
            ]);

            return $this->tokenHeaders->apply(new HtmlResponse($html, 422));
        }

        try {
            $this->passwordReset->complete($secret, $password, $this->clientIp($request));
        } catch (ValidationException $exception) {
            $message = $exception->getMessage();
            $html = $this->renderer->render('pages/auth/reset_form', [
                'title' => 'Choose a new password',
                'csrf' => $csrf,
                'error' => $message,
            ]);

            return $this->tokenHeaders->apply(new HtmlResponse($html, 422));
        } catch (DomainRuleException) {
            $response = new RedirectResponse('/reset-password/result?status=invalid_or_expired', 302);
            $response = $response->withAddedHeader('Set-Cookie', $this->cookies->buildResetAuthClearCookie());

            return $this->tokenHeaders->apply($response);
        }

        $response = new RedirectResponse('/reset-password/result?status=success', 302);
        $response = $response->withAddedHeader('Set-Cookie', $this->cookies->buildResetAuthClearCookie());

        return $this->tokenHeaders->apply($response);
    }

    public function result(ServerRequestInterface $request): ResponseInterface
    {
        $status = (string) ($request->getQueryParams()['status'] ?? 'invalid_or_expired');
        if ($status !== 'success') {
            $status = 'invalid_or_expired';
        }

        $html = $this->renderer->render('pages/auth/reset_result', [
            'title' => 'Password reset',
            'status' => $status,
        ]);

        $response = $this->tokenHeaders->apply(new HtmlResponse($html));
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->cookies->buildClearCookie(TokenPurpose::PASSWORD_RESET),
        );
        $response = $response->withAddedHeader('Set-Cookie', $this->cookies->buildResetAuthClearCookie());

        return $response;
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
