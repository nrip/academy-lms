<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Credentials;

use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Credentials\DocumentFileValidator;
use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DocumentFileValidatorTest extends TestCase
{
    private DocumentFileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DocumentFileValidator();
    }

    public function testAcceptsAllowedPdfWithinSizeLimit(): void
    {
        $sanitized = $this->validator->assertAllowed(
            $this->requirement(),
            'application/pdf',
            2048,
            'My Certificate.pdf',
        );

        self::assertSame('My Certificate.pdf', $sanitized);
    }

    public function testRejectsZeroOrNegativeSize(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->assertAllowed($this->requirement(), 'application/pdf', 0, 'file.pdf');
    }

    public function testRejectsFileLargerThanRequirementLimit(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->assertAllowed($this->requirement(maxSizeBytes: 1000), 'application/pdf', 2000, 'file.pdf');
    }

    public function testRejectsFileLargerThanPlatformCap(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->assertAllowed($this->requirement(maxSizeBytes: 999999999), 'application/pdf', 20 * 1024 * 1024, 'file.pdf');
    }

    public function testRejectsDeniedExtension(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->assertAllowed($this->requirement(acceptedFileTypes: 'pdf,exe'), 'application/x-msdownload', 1024, 'malware.exe');
    }

    public function testRejectsFileTypeNotAcceptedByRequirement(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->assertAllowed($this->requirement(acceptedFileTypes: 'pdf'), 'image/png', 1024, 'file.png');
    }

    public function testRejectsMimeExtensionMismatch(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->assertAllowed($this->requirement(acceptedFileTypes: 'pdf,png'), 'image/png', 1024, 'file.pdf');
    }

    public function testSanitizeFilenameStripsPathAndDangerousCharacters(): void
    {
        $sanitized = $this->validator->sanitizeFilename('../../etc/passwd');
        self::assertStringNotContainsString('..', $sanitized);
        self::assertStringNotContainsString('/', $sanitized);
    }

    public function testSanitizeFilenameFallsBackWhenEmptyAfterSanitization(): void
    {
        $sanitized = $this->validator->sanitizeFilename('...');
        self::assertSame('document', $sanitized);
    }

    public function testSanitizeFilenameTruncatesVeryLongNames(): void
    {
        $longName = str_repeat('a', 300) . '.pdf';
        $sanitized = $this->validator->sanitizeFilename($longName);

        self::assertLessThanOrEqual(180, strlen($sanitized));
        self::assertStringEndsWith('.pdf', $sanitized);
    }

    private function requirement(
        int $maxSizeBytes = 10485760,
        string $acceptedFileTypes = 'pdf,jpg,jpeg,png',
    ): CourseDocumentRequirement {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new CourseDocumentRequirement(
            requirementId: 1,
            courseVersionId: 1,
            documentName: 'Medical registration certificate',
            description: 'Upload your current registration certificate.',
            mandatory: true,
            acceptedFileTypes: $acceptedFileTypes,
            maxSizeBytes: $maxSizeBytes,
            singleOrMultiple: 'single',
            reuseAllowed: false,
            reviewerInstructions: null,
            sortOrder: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
