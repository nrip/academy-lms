<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

/**
 * Email verification drives activation; mobile verification never changes account_status.
 */
final class AccountActivationPolicy
{
    /**
     * @return array{set_email_verified: bool, activate: bool, new_status: string}
     */
    public static function applyEmailVerified(string $currentStatus, bool $emailAlreadyVerified): array
    {
        AccountStatus::assertValid($currentStatus);

        if ($emailAlreadyVerified) {
            return [
                'set_email_verified' => false,
                'activate' => false,
                'new_status' => $currentStatus,
            ];
        }

        if ($currentStatus === AccountStatus::PENDING_VERIFICATION) {
            return [
                'set_email_verified' => true,
                'activate' => true,
                'new_status' => AccountStatus::ACTIVE,
            ];
        }

        // active, suspended, or any other valid status: stamp email only; never activate suspended.
        return [
            'set_email_verified' => true,
            'activate' => false,
            'new_status' => $currentStatus,
        ];
    }
}
