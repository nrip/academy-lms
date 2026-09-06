<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Certificates;

use Academy\Domain\Certificates\Certificate;
use Academy\Infrastructure\Certificates\SimpleCertificatePdfRenderer;
use Academy\Infrastructure\View\Escaper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class SimpleCertificatePdfRendererTest extends TestCase
{
    public function testPdfContainsCertificateFieldsMatchingHtmlTemplate(): void
    {
        $certificate = $this->certificate(
            learnerName: 'Priya Nair',
            courseTitle: 'Phase 1 Demo — Obesity Learning Pathway',
            number: 'ACAD-TEST-PDF-001',
        );
        $renderer = $this->renderer();
        $verifyUrl = 'http://localhost:8080/verify/certificates/ACAD-TEST-PDF-001';

        $html = $renderer->renderHtml($certificate, $verifyUrl);
        self::assertStringContainsString('Priya Nair', $html);
        self::assertStringContainsString('Phase 1 Demo — Obesity Learning Pathway', $html);
        self::assertStringContainsString('ACAD-TEST-PDF-001', $html);
        self::assertStringContainsString('Contoso CME Board', $html);
        self::assertStringContainsString('#1A5F9E', $html);
        self::assertStringContainsString($verifyUrl, $html);

        $pdf = $renderer->render($certificate, $verifyUrl);
        self::assertStringStartsWith('%PDF', $pdf);

        $text = (new Parser())->parseContent($pdf)->getText();
        self::assertStringContainsString('Priya Nair', $text);
        self::assertStringContainsString('Phase 1 Demo', $text);
        self::assertStringContainsString('Obesity Learning Pathway', $text);
        self::assertStringContainsString('ACAD-TEST-PDF-001', $text);
        self::assertStringContainsString('verify/certificates/ACAD-TEST-PDF-001', $text);
    }

    public function testUnicodeLearnerAndCourseNamesArePreserved(): void
    {
        $certificate = $this->certificate(
            learnerName: 'डॉ. प्रिया नायर',
            courseTitle: 'मेटाबॉलिक स्वास्थ्य — CME 101',
            versionTitle: 'संस्करण १',
            number: 'ACAD-UNI-日本語-001',
            label: 'पूर्णता प्रमाणपत्र',
        );
        $renderer = $this->renderer();

        $html = $renderer->renderHtml(
            $certificate,
            'http://localhost:8080/verify/certificates/' . rawurlencode($certificate->certificateNumber),
        );
        self::assertStringContainsString('डॉ. प्रिया नायर', $html);
        self::assertStringContainsString('मेटाबॉलिक स्वास्थ्य — CME 101', $html);
        self::assertStringContainsString('पूर्णता प्रमाणपत्र', $html);

        $pdf = $renderer->render($certificate);
        $text = (new Parser())->parseContent($pdf)->getText();
        self::assertStringContainsString('डॉ. प्रिया नायर', $text);
        self::assertStringContainsString('मेटाबॉलिक स्वास्थ्य', $text);
        self::assertStringContainsString('CME 101', $text);
        self::assertStringContainsString('पूर्णता प्रमाणपत्र', $text);
        self::assertStringContainsString('ACAD-UNI-', $text);
    }

    private function renderer(): SimpleCertificatePdfRenderer
    {
        return new SimpleCertificatePdfRenderer(
            new Escaper(),
            dirname(__DIR__, 3) . '/../templates',
            'http://localhost:8080',
            'Contoso CME Board',
            '#1A5F9E',
        );
    }

    private function certificate(
        string $learnerName,
        string $courseTitle,
        string $number,
        string $versionTitle = 'Version 1',
        string $label = 'Certificate of Completion',
    ): Certificate {
        $now = new DateTimeImmutable('2026-09-06 12:00:00');

        return new Certificate(
            certificateId: 1,
            enrolmentId: 2,
            courseVersionId: 3,
            certificateType: 'completion',
            certificateNumber: $number,
            verificationHash: str_repeat('ab', 32),
            learnerNameSnapshot: $learnerName,
            courseTitleSnapshot: $courseTitle,
            versionTitleSnapshot: $versionTitle,
            certificateLabel: $label,
            status: 'active',
            currentMarker: 1,
            issuedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
