<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use DateTimeImmutable;

/**
 * Login may succeed only for active, email-verified, unlocked accounts.
 * Pending-verification and suspended users never receive an authenticated session.
 */
final class LoginEligibility
{
    public const REASON_OK = 'ok';
    public const REASON_PENDING = 'pending_verification';
    public const REASON_SUSPENDED = 'suspended';
    public const REASON_UNVERIFIED = 'email_unverified';
    public const REASON_LOCKED = 'locked';

    /**
     * @param array{
     *   account_status: string,
     *   email_verified_at: ?string,
     *   locked_until: ?DateTimeImmutable
     * } $user
     */
    public static function evaluate(array $user, DateTimeImmutable $now): string
    {
        if ($user['account_status'] === AccountStatus::SUSPENDED) {
            return self::REASON_SUSPENDED;
        }

        if ($user['account_status'] === AccountStatus::PENDING_VERIFICATION) {
            return self::REASON_PENDING;
        }

        if ($user['account_status'] !== AccountStatus::ACTIVE) {
            return self::REASON_PENDING;
        }

        if ($user['email_verified_at'] === null) {
            return self::REASON_UNVERIFIED;
        }

        if (LoginLockoutPolicy::isLocked($user['locked_until'], $now)) {
            return self::REASON_LOCKED;
        }

        return self::REASON_OK;
    }

    public static function mayReceivePasswordReset(string $accountStatus): bool
    {
        return in_array($accountStatus, [
            AccountStatus::ACTIVE,
            AccountStatus::PENDING_VERIFICATION,
        ], true);
    }
}
