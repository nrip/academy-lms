<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Certificates;

use Academy\Application\Branding\AcademyBranding;
use Academy\Domain\Certificates\Certificate;
use Academy\Infrastructure\View\Escaper;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * HTML → PDF certificate renderer (Dompdf + DejaVu Sans for Unicode).
 */
final class SimpleCertificatePdfRenderer
{
    private readonly string $certificateIssuerName;
    private readonly string $primaryColor;

    public function __construct(
        private readonly Escaper $escaper,
        private readonly string $templatePath,
        private readonly string $appUrl,
        string $certificateIssuerName = AcademyBranding::DEFAULT_NAME,
        string $primaryColor = AcademyBranding::DEFAULT_PRIMARY_COLOR,
    ) {
        $branding = AcademyBranding::fromConfig([
            'certificate_issuer_name' => $certificateIssuerName,
            'primary_color' => $primaryColor,
        ]);
        $this->certificateIssuerName = $branding->certificateIssuerName;
        $this->primaryColor = $branding->primaryColor;
    }

    public function render(Certificate $certificate, ?string $verifyUrl = null): string
    {
        $resolvedVerifyUrl = $this->absoluteUrl(
            $verifyUrl ?? $this->defaultVerifyUrl($certificate),
        );
        $html = $this->renderHtml($certificate, $resolvedVerifyUrl);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot($this->templatePath);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        if (!is_string($output) || $output === '') {
            throw new RuntimeException('Certificate PDF rendering produced an empty document.');
        }

        return $output;
    }

    public function renderHtml(Certificate $certificate, string $verifyUrl): string
    {
        $file = $this->templatePath . '/pdf/certificate.php';
        if (!is_readable($file)) {
            throw new RuntimeException('Certificate PDF template was not found.');
        }

        $e = $this->escaper;
        $academyName = $this->certificateIssuerName;
        $primaryColor = $this->primaryColor;
        $issued = $certificate->issuedAt
            ->setTimezone(new \DateTimeZone('Asia/Kolkata'))
            ->format('d M Y');
        $verifyUrl = $this->absoluteUrl($verifyUrl);

        ob_start();
        require $file;
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            throw new RuntimeException('Certificate PDF template rendering failed.');
        }

        return $html;
    }

    private function defaultVerifyUrl(Certificate $certificate): string
    {
        return '/verify/certificates/' . rawurlencode($certificate->certificateNumber);
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($this->appUrl, '/') . '/' . ltrim($url, '/');
    }
}
