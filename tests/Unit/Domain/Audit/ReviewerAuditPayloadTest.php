<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\ReviewerAuditPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewerAuditPayloadTest extends TestCase
{
    public function testAllowListedFieldsAreAccepted(): void
    {
        $payload = new ReviewerAuditPayload(
            action: 'reviewer.application_claimed',
            entityType: 'application',
            entityId: '12',
            previous: ['assignment_id' => null],
            next: [
                'user_id' => 5,
                'application_id' => 12,
                'application_number' => 'APP-001',
                'assignment_id' => 99,
                'reviewer_user_id' => 5,
                'status' => 'under_review',
                'state_version' => 2,
                'result' => 'ok',
            ],
        );

        self::assertSame('reviewer.application_claimed', $payload->action());
        self::assertNotNull($payload->newValue());
    }

    #[DataProvider('nonAllowListedFields')]
    public function testNonAllowListedFieldsAreRejected(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReviewerAuditPayload(
            action: 'document.verified',
            entityType: 'document_submission',
            entityId: '7',
            next: [$field => 'secret'],
        );
    }

    public function testDisallowsFilenameInAuditPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReviewerAuditPayload(
            action: 'document.verified',
            entityType: 'document_submission',
            entityId: '7',
            next: ['display_filename' => 'certificate.pdf'],
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonAllowListedFields(): array
    {
        return [
            ['display_filename'],
            ['object_key'],
            ['signed_document_url'],
            ['learner_visible_message'],
            ['internal_note'],
            ['email'],
        ];
    }
}
