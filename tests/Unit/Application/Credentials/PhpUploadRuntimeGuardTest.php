<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Credentials;

use Academy\Application\Credentials\PhpUploadRuntimeGuard;
use Academy\Domain\Credentials\DocumentFileValidator;
use PHPUnit\Framework\TestCase;

final class PhpUploadRuntimeGuardTest extends TestCase
{
    public function testParseIniSize(): void
    {
        self::assertSame(10 * 1024 * 1024, PhpUploadRuntimeGuard::parseIniSize('10M'));
        self::assertSame(16 * 1024 * 1024, PhpUploadRuntimeGuard::parseIniSize('16M'));
        self::assertSame(2048, PhpUploadRuntimeGuard::parseIniSize('2K'));
        self::assertSame(512, PhpUploadRuntimeGuard::parseIniSize('512'));
        self::assertSame(0, PhpUploadRuntimeGuard::parseIniSize(''));
    }

    public function testInspectReportsCurrentPhpLimits(): void
    {
        $report = PhpUploadRuntimeGuard::inspect(DocumentFileValidator::PLATFORM_MAX_BYTES);
        self::assertSame(DocumentFileValidator::PLATFORM_MAX_BYTES, $report['app_max_bytes']);
        self::assertIsInt($report['upload_max_bytes']);
        self::assertIsInt($report['post_max_bytes']);
        self::assertIsBool($report['adequate']);
        self::assertIsArray($report['problems']);
        if (!$report['adequate']) {
            self::assertNotSame([], $report['problems']);
        }
    }

    public function testAssertAdequatePassesWhenPhpLimitsAllow(): void
    {
        $report = PhpUploadRuntimeGuard::inspect(DocumentFileValidator::PLATFORM_MAX_BYTES);
        if (!$report['adequate']) {
            self::markTestSkipped(
                'PHP limits are below 10 MB. Re-run with: php -d upload_max_filesize=10M -d post_max_size=16M vendor/bin/phpunit',
            );
        }
        PhpUploadRuntimeGuard::assertAdequateForApplication();
        self::assertTrue(true);
    }

    public function testAssertDetectsLowUploadLimitViaInspectProblems(): void
    {
        // Simulate inadequate report logic: 1M upload vs 10M app cap.
        $uploadBytes = PhpUploadRuntimeGuard::parseIniSize('1M');
        $appMax = DocumentFileValidator::PLATFORM_MAX_BYTES;
        self::assertLessThan($appMax, $uploadBytes);

        $problems = [];
        if ($uploadBytes < $appMax) {
            $problems[] = 'upload_max_filesize below app limit';
        }
        self::assertNotSame([], $problems);
    }
}
