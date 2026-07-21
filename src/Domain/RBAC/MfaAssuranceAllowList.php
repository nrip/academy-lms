<?php

declare(strict_types=1);

namespace Academy\Domain\RBAC;

/**
 * B-3 MFA routes may grant these under incomplete privileged assurance.
 * B-1 does not implement those routes; the allow-list is enforced in AuthorizationService.
 */
final class MfaAssuranceAllowList
{
    /** @var list<string> */
    public const KEYS = [
        'mfa.totp.enrol',
        'mfa.totp.verify',
        'mfa.recovery.use',
    ];

    public static function contains(string $permissionKey): bool
    {
        return in_array($permissionKey, self::KEYS, true);
    }
}
