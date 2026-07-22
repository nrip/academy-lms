<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\IdentityProfileAuditPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentityProfileAuditPayloadTest extends TestCase
{
    public function testAllowListedFieldsAccepted(): void
    {
        $payload = new IdentityProfileAuditPayload(
            'profile.personal_updated',
            'learner_profile',
            '7',
            next: [
                'user_id' => 7,
                'learner_profile_id' => 7,
                'changed_field_keys' => 'first_name,last_name',
                'row_version_before' => 1,
                'row_version_after' => 2,
                'result' => 'ok',
            ],
        );

        self::assertSame('profile.personal_updated', $payload->action());
        self::assertSame('learner_profile', $payload->affectedEntityType());
        self::assertSame('7', $payload->affectedEntityId());
    }

    public function testQualificationFieldsAccepted(): void
    {
        $payload = new IdentityProfileAuditPayload(
            'profile.qualification_added',
            'learner_qualification',
            '3',
            next: [
                'user_id' => 7,
                'learner_qualification_id' => 3,
                'qualification_type' => 'Degree',
                'result' => 'ok',
            ],
        );

        self::assertSame('profile.qualification_added', $payload->action());
    }

    #[DataProvider('sensitiveFields')]
    public function testSensitiveFieldsRejected(string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IdentityProfileAuditPayload(
            'profile.personal_updated',
            'learner_profile',
            '7',
            next: [$field => 'sensitive-value'],
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function sensitiveFields(): array
    {
        return [
            ['address_line_1'],
            ['alternate_mobile'],
            ['date_of_birth'],
            ['medical_council_registration_number'],
            ['postal_code'],
            ['first_name'],
        ];
    }
}
