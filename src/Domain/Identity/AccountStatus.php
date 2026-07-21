<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

final class AccountStatus
{
    public const PENDING_VERIFICATION = 'pending_verification';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    /** @var list<string> */
    public const ALL = [
        self::PENDING_VERIFICATION,
        self::ACTIVE,
        self::SUSPENDED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid account status.');
        }
    }
}
