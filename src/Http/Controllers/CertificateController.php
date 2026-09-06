<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Certificates\CertificateQueryService;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\Certificates\SimpleCertificatePdfRenderer;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CertificateController
{
    public function __construct(
        private readonly CertificateQueryService $query,
        private readonly SimpleCertificatePdfRenderer $pdf,
        private readonly PhpRenderer $renderer,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function listForEnrolment(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $view = $this->query->listForEnrolment($this->auth($request), $enrolmentId);
        $html = $this->renderer->render('pages/certificates/list', [
            'title' => 'Certificates',
            'csrf' => $this->csrf($request),
            'view' => $view,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $certificateId = (int) ($args['certificateId'] ?? 0);
        $certificate = $this->query->getOwnedCertificate($this->auth($request), $certificateId);
        $html = $this->renderer->render('pages/certificates/show', [
            'title' => 'Certificate',
            'csrf' => $this->csrf($request),
            'certificate' => $certificate,
            'verifyUrl' => '/verify/certificates/' . rawurlencode($certificate->certificateNumber),
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function downloadPdf(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $certificateId = (int) ($args['certificateId'] ?? 0);
        $certificate = $this->query->getOwnedCertificate($this->auth($request), $certificateId);
        $bytes = $this->pdf->render($certificate);
        $filename = $certificate->certificateNumber . '.pdf';

        $response = new Response();
        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    /**
     * @param array<string, string> $args
     */
    public function verifyPublic(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->rateLimiter->hit('public.certificate_verify', [
            ['type' => 'ip', 'value' => is_string($ip) ? $ip : '0.0.0.0'],
        ]);

        $number = rawurldecode((string) ($args['certificateNumber'] ?? ''));
        $view = $this->query->findPublicByNumber($number);
        $html = $this->renderer->render('pages/certificates/verify', [
            'title' => 'Certificate verification',
            'csrf' => $this->csrf($request),
            'view' => $view,
            'certificateNumber' => $number,
        ]);

        return new HtmlResponse($html, $view === null ? 404 : 200);
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }
}
