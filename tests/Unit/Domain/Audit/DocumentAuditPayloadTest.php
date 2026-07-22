<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\DocumentAuditPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentAuditPayloadTest extends TestCase
{
    public function testAllowListedFieldsAreAccepted(): void
    {
        $payload = new DocumentAuditPayload(
            action: 'document.upload_confirmed',
            entityType: 'document_submission',
            entityId: '42',
            next: [
                'application_id' => 7,
                'requirement_id' => 3,
                'document_submission_id' => 42,
                'old_document_submission_id' => 41,
                'status' => 'uploaded',
                'scan_status' => 'pending',
                'state_version' => 2,
                'row_version' => 1,
                'result' => 'ok',
                'reason_code' => null,
                'declaration_version' => '2026-07-22',
                'scan_attempt_count' => 0,
                'object_key_suffix' => 'abcd1234',
            ],
        );

        self::assertSame('document.upload_confirmed', $payload->action());
        self::assertSame('document_submission', $payload->affectedEntityType());
        self::assertSame('42', $payload->affectedEntityId());
        self::assertNotNull($payload->newValue());
        self::assertNull($payload->previousValue());
    }

    #[DataProvider('nonAllowListedFields')]
    public function testNonAllowListedFieldsAreRejected(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentAuditPayload(
            action: 'document.upload_confirmed',
            entityType: 'document_submission',
            entityId: '42',
            next: [$field => 'value'],
        );
    }

    public function testDisallowsSensitiveDocumentIdentifiersEvenInPreviousValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentAuditPayload(
            action: 'document.upload_confirmed',
            entityType: 'document_submission',
            entityId: '42',
            previous: ['object_key' => 'documents/42/file.pdf'],
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonAllowListedFields(): array
    {
        return [
            ['object_key'],
            ['display_filename'],
            ['signed_document_url'],
            ['checksum_sha256'],
            ['payment_amount'],
            ['first_name'],
        ];
    }
}
