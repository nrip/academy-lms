<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\MobileOtpResendService;
use Academy\Application\Identity\MobileOtpVerificationService;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MobileVerificationController
{
    public function __construct(
        private readonly MobileOtpVerificationService $verification,
        private readonly MobileOtpResendService $resend,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function showForm(ServerRequestInterface $request): ResponseInterface
    {
        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
        $hasPendingMarker = $this->pendingUserIdFromSession($request) !== null;
        $status = (string) ($request->getQueryParams()['status'] ?? '');

        $html = $this->renderer->render('pages/verify/mobile', [
            'title' => 'Verify mobile number',
            'csrf' => $csrf,
            'hasPendingMarker' => $hasPendingMarker,
            'status' => $status,
        ]);

        return new HtmlResponse($html);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $otp = isset($body['otp']) && is_string($body['otp']) ? trim($body['otp']) : '';
        $mobile = isset($body['mobile']) && is_string($body['mobile']) ? trim($body['mobile']) : '';
        $mobileIdentifier = $mobile !== '' ? $mobile : null;

        try {
            $this->verification->verify(
                $this->pendingUserIdFromSession($request),
                $mobileIdentifier,
                $otp,
                $this->clientIp($request),
            );
        } catch (DomainRuleException) {
            return new RedirectResponse('/verify-mobile?status=invalid', 302);
        }

        return new RedirectResponse('/verify-mobile?status=success', 302);
    }

    public function resend(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $mobile = isset($body['mobile']) && is_string($body['mobile']) ? trim($body['mobile']) : '';
        $mobileIdentifier = $mobile !== '' ? $mobile : null;

        $this->resend->resend(
            $this->pendingUserIdFromSession($request),
            $mobileIdentifier,
            $this->clientIp($request),
        );

        return new RedirectResponse('/verify-mobile?status=resent', 302);
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
