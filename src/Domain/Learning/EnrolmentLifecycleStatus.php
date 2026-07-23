<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

final class EnrolmentLifecycleStatus
{
    public const SCHEDULED = 'scheduled';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const WITHDRAWN = 'withdrawn';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const ACCESS_EXPIRED = 'access_expired';

    /** @var list<string> */
    public const ALL = [
        self::SCHEDULED,
        self::ACTIVE,
        self::SUSPENDED,
        self::WITHDRAWN,
        self::CANCELLED,
        self::REFUNDED,
        self::ACCESS_EXPIRED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Unknown enrolment lifecycle status.');
        }
    }

    public static function occupiesCapacity(string $status): bool
    {
        return in_array($status, [self::SCHEDULED, self::ACTIVE], true);
    }
}
