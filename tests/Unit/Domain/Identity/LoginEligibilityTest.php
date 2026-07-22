<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Identity\LoginEligibility;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LoginEligibilityTest extends TestCase
{
    public function testOnlyActiveVerifiedUnlockedMayLogin(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::assertSame(LoginEligibility::REASON_OK, LoginEligibility::evaluate([
            'account_status' => 'active',
            'email_verified_at' => '2026-07-22 10:00:00.000000',
            'locked_until' => null,
        ], $now));
    }

    public function testPendingSuspendedUnverifiedLockedDenied(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::assertSame(LoginEligibility::REASON_PENDING, LoginEligibility::evaluate([
            'account_status' => 'pending_verification',
            'email_verified_at' => null,
            'locked_until' => null,
        ], $now));
        self::assertSame(LoginEligibility::REASON_SUSPENDED, LoginEligibility::evaluate([
            'account_status' => 'suspended',
            'email_verified_at' => '2026-07-22 10:00:00.000000',
            'locked_until' => null,
        ], $now));
        self::assertSame(LoginEligibility::REASON_UNVERIFIED, LoginEligibility::evaluate([
            'account_status' => 'active',
            'email_verified_at' => null,
            'locked_until' => null,
        ], $now));
        self::assertSame(LoginEligibility::REASON_LOCKED, LoginEligibility::evaluate([
            'account_status' => 'active',
            'email_verified_at' => '2026-07-22 10:00:00.000000',
            'locked_until' => $now->modify('+5 minutes'),
        ], $now));
    }

    public function testResetEligibilityDeniesSuspended(): void
    {
        self::assertTrue(LoginEligibility::mayReceivePasswordReset('active'));
        self::assertTrue(LoginEligibility::mayReceivePasswordReset('pending_verification'));
        self::assertFalse(LoginEligibility::mayReceivePasswordReset('suspended'));
    }
}
