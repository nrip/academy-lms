<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Certificates;

use Academy\Domain\Certificates\Certificate;
use Academy\Infrastructure\Certificates\SimpleCertificatePdfRenderer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SimpleCertificatePdfRendererTest extends TestCase
{
    public function testRenderedPdfHasParsableContentObjectAndVisibleText(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00');
        $certificate = new Certificate(
            certificateId: 1,
            enrolmentId: 2,
            courseVersionId: 3,
            certificateType: 'completion',
            certificateNumber: 'ACAD-TEST-PDF-001',
            verificationHash: str_repeat('ab', 32),
            learnerNameSnapshot: 'Priya Nair',
            courseTitleSnapshot: 'Phase 1 Demo Obesity Learning Pathway',
            versionTitleSnapshot: 'Phase 1 Demo Obesity Learning Pathway (v1)',
            certificateLabel: 'Certificate of Completion',
            status: 'active',
            currentMarker: 1,
            issuedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );

        $pdf = (new SimpleCertificatePdfRenderer())->render($certificate);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString("4 0 obj\n<< /Length ", $pdf);
        self::assertStringNotContainsString('4 0 obj\\n', $pdf);
        self::assertStringContainsString('(Priya Nair) Tj', $pdf);
        self::assertStringContainsString('ACAD-TEST-PDF-001', $pdf);
        self::assertMatchesRegularExpression('/stream\nBT /', $pdf);
    }
}
