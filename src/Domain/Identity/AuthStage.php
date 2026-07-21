<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

final class AuthStage
{
    public const ANONYMOUS = 'anonymous';
    public const MFA_ENROLMENT_REQUIRED = 'mfa_enrolment_required';
    public const MFA_CHALLENGE_REQUIRED = 'mfa_challenge_required';
    public const FULLY_AUTHENTICATED = 'fully_authenticated';

    /** @var list<string> */
    public const KNOWN = [
        self::ANONYMOUS,
        self::MFA_ENROLMENT_REQUIRED,
        self::MFA_CHALLENGE_REQUIRED,
        self::FULLY_AUTHENTICATED,
    ];

    public static function isKnown(?string $stage): bool
    {
        return $stage !== null && in_array($stage, self::KNOWN, true);
    }

    /**
     * Privileged users never infer fully_authenticated.
     * Non-privileged users may infer fully_authenticated when stage is missing/invalid.
     */
    public static function resolveEffective(?string $rawStage, bool $hasPrivilegedRole): string
    {
        $usable = ($rawStage !== null && self::isKnown($rawStage) && $rawStage !== self::ANONYMOUS)
            ? $rawStage
            : null;

        if ($hasPrivilegedRole) {
            return $usable ?? self::MFA_ENROLMENT_REQUIRED;
        }

        return $usable ?? self::FULLY_AUTHENTICATED;
    }
}
