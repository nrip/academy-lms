<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Audit;

use Academy\Domain\Audit\IdentityRegistrationAuditPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentityRegistrationAuditPayloadTest extends TestCase
{
    public function testAcceptsAllowListedFields(): void
    {
        $payload = new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: [
                'user_id' => 1,
                'account_status' => 'pending_verification',
            ],
        );

        self::assertSame(1, $payload->newValue()['user_id']);
    }

    public function testRejectsEmailField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: ['email' => 'learner@example.test'],
        );
    }

    public function testRejectsMobileField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: ['mobile' => '+919876543210'],
        );
    }

    public function testRejectsMobileE164Field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: ['mobile_e164' => '+919876543210'],
        );
    }

    public function testRejectsTokenField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: ['token' => 'deadbeef'],
        );
    }

    public function testRejectsRawTokenField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: ['raw_token' => 'deadbeef'],
        );
    }

    public function testRejectsOtpField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            next: ['otp' => '123456'],
        );
    }

    public function testRejectsUnlistedFieldInPreviousValueToo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
            previous: ['password' => 'secret'],
        );
    }

    public function testEmptyPreviousAndNextAreNullNotEmptyArray(): void
    {
        $payload = new IdentityRegistrationAuditPayload(
            action: 'identity.registered',
            entityType: 'user',
            entityId: '1',
        );

        self::assertNull($payload->previousValue());
        self::assertNull($payload->newValue());
    }
}
