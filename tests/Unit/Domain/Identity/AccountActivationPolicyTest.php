<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Identity;

use Academy\Domain\Identity\AccountActivationPolicy;
use Academy\Domain\Identity\AccountStatus;
use PHPUnit\Framework\TestCase;

final class AccountActivationPolicyTest extends TestCase
{
    public function testPendingVerificationBecomesActiveOnFirstEmailVerification(): void
    {
        $decision = AccountActivationPolicy::applyEmailVerified(AccountStatus::PENDING_VERIFICATION, false);

        self::assertTrue($decision['set_email_verified']);
        self::assertTrue($decision['activate']);
        self::assertSame(AccountStatus::ACTIVE, $decision['new_status']);
    }

    public function testSuspendedNeverActivatesOnEmailVerification(): void
    {
        $decision = AccountActivationPolicy::applyEmailVerified(AccountStatus::SUSPENDED, false);

        self::assertTrue($decision['set_email_verified']);
        self::assertFalse($decision['activate']);
        self::assertSame(AccountStatus::SUSPENDED, $decision['new_status']);
    }

    public function testActiveStaysActiveOnEmailVerification(): void
    {
        $decision = AccountActivationPolicy::applyEmailVerified(AccountStatus::ACTIVE, false);

        self::assertTrue($decision['set_email_verified']);
        self::assertFalse($decision['activate']);
        self::assertSame(AccountStatus::ACTIVE, $decision['new_status']);
    }

    public function testIdempotentWhenEmailAlreadyVerifiedFromPending(): void
    {
        $decision = AccountActivationPolicy::applyEmailVerified(AccountStatus::PENDING_VERIFICATION, true);

        self::assertFalse($decision['set_email_verified']);
        self::assertFalse($decision['activate']);
        self::assertSame(AccountStatus::PENDING_VERIFICATION, $decision['new_status']);
    }

    public function testIdempotentWhenEmailAlreadyVerifiedFromSuspended(): void
    {
        $decision = AccountActivationPolicy::applyEmailVerified(AccountStatus::SUSPENDED, true);

        self::assertFalse($decision['set_email_verified']);
        self::assertFalse($decision['activate']);
        self::assertSame(AccountStatus::SUSPENDED, $decision['new_status']);
    }

    public function testIdempotentWhenEmailAlreadyVerifiedFromActive(): void
    {
        $decision = AccountActivationPolicy::applyEmailVerified(AccountStatus::ACTIVE, true);

        self::assertFalse($decision['set_email_verified']);
        self::assertFalse($decision['activate']);
        self::assertSame(AccountStatus::ACTIVE, $decision['new_status']);
    }

    public function testRejectsInvalidAccountStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AccountActivationPolicy::applyEmailVerified('bogus_status', false);
    }
}
