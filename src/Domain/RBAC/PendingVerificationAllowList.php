<?php

declare(strict_types=1);

namespace Academy\Domain\RBAC;

/**
 * Permissions permitted while users.account_status = pending_verification.
 * All other keys remain denied until the account is active.
 */
final class PendingVerificationAllowList
{
    /** @var list<string> */
    private const KEYS = [
        'identity.session.view_own',
        'identity.session.revoke_own',
        'identity.password.change_own',
        'identity.verification.view_own',
        'identity.verification.resend_own',
    ];

    public static function contains(string $permissionKey): bool
    {
        return in_array($permissionKey, self::KEYS, true);
    }

    /**
     * Privileged / sensitive keys that must never appear on the pending allow-list (regression).
     *
     * @return list<string>
     */
    public static function privilegedOrSensitiveKeysForRegression(): array
    {
        return [
            'finance.dashboard.view',
            'finance.payment.view',
            'finance.refund.approve',
            'document.metadata.view',
            'document.signed_url.generate',
            'rbac.role.assign',
            'rbac.role.revoke',
            'reviewer.assignment.create',
            'reviewer.assignment.revoke',
            'reviewer.assignment.view',
            'reviewer.assignment.view_own',
            'reviewer.queue.view',
            'reviewer.application.view',
            'reviewer.document.review',
            'reviewer.document.history',
            'account.suspend',
            'account.activate',
            'account.unlock',
            'audit.view',
            'application.create',
            'profile.view_any',
            'profile.edit_any',
            'mfa.reset',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::KEYS;
    }
}
