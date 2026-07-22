<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\AdmissionsAuditPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdmissionsAuditPayloadTest extends TestCase
{
    public function testAllowListedFieldsAccepted(): void
    {
        $payload = new AdmissionsAuditPayload(
            'application.draft_created',
            'application',
            '42',
            next: [
                'user_id' => 7,
                'application_id' => 42,
                'course_id' => 1,
                'course_version_id' => 10,
                'batch_id' => 100,
                'status' => 'draft',
                'result' => 'ok',
            ],
        );

        self::assertSame('application.draft_created', $payload->action());
        self::assertSame('application', $payload->affectedEntityType());
        self::assertSame('42', $payload->affectedEntityId());
        self::assertNotNull($payload->newValue());
        self::assertNull($payload->previousValue());
    }

    #[DataProvider('nonAllowListedFields')]
    public function testNonAllowListedFieldsRejected(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AdmissionsAuditPayload(
            'application.draft_created',
            'application',
            '42',
            next: [$field => 'value'],
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonAllowListedFields(): array
    {
        return [
            ['document_metadata'],
            ['signed_document_url'],
            ['payment_amount'],
            ['submitted_at'],
            ['first_name'],
        ];
    }
}
