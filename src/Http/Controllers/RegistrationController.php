<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\RegistrationService;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RegistrationController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly SessionService $sessions,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function showForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderForm($request);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : '';
        $mobile = isset($body['mobile']) && is_string($body['mobile']) ? $body['mobile'] : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $termsAccepted = !empty($body['terms_accepted']);
        $privacyAccepted = !empty($body['privacy_accepted']);

        try {
            $result = $this->registration->register(
                $email,
                $mobile,
                $password,
                $termsAccepted,
                $privacyAccepted,
                $this->clientIp($request),
            );
        } catch (ValidationException $exception) {
            return $this->renderForm($request, [
                'email' => trim($email),
                'mobile' => trim($mobile),
            ], $exception->fields(), 422);
        }

        if ($result->created && $result->userId !== null) {
            /** @var SessionRecord|null $session */
            $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
            if ($session instanceof SessionRecord) {
                $this->sessions->storePendingVerificationMarker($session, $result->userId);
            }
        }

        return new RedirectResponse('/register/pending', 302);
    }

    public function pending(ServerRequestInterface $request): ResponseInterface
    {
        $hasPendingMarker = $this->pendingUserIdFromSession($request) !== null;

        $html = $this->renderer->render('pages/register/pending', [
            'title' => 'Check your email',
            'hasPendingMarker' => $hasPendingMarker,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, list<string>> $errors
     */
    private function renderForm(
        ServerRequestInterface $request,
        array $values = ['email' => '', 'mobile' => ''],
        array $errors = [],
        int $status = 200,
    ): ResponseInterface {
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        $html = $this->renderer->render('pages/register/form', [
            'title' => 'Create account',
            'csrf' => $csrf,
            'values' => $values,
            'errors' => $errors,
        ]);

        return new HtmlResponse($html, $status);
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
