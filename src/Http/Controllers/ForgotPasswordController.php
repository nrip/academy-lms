<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\ForgotPasswordService;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ForgotPasswordController
{
    public function __construct(
        private readonly ForgotPasswordService $forgotPassword,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function showForm(ServerRequestInterface $request): ResponseInterface
    {
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        $html = $this->renderer->render('pages/auth/forgot_password', [
            'title' => 'Forgot password',
            'csrf' => $csrf,
        ]);

        return new HtmlResponse($html);
    }

    public function request(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : '';

        $this->forgotPassword->request($email, $this->clientIp($request));

        return new RedirectResponse('/forgot-password/sent', 302);
    }

    public function sent(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);
        $html = $this->renderer->render('pages/auth/forgot_password_sent', [
            'title' => 'Check your email',
        ]);

        return new HtmlResponse($html);
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
