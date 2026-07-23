<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use Academy\Domain\Exception\DomainRuleException;

/**
 * Delivery row status for transactional notifications (WP-07).
 * Distinct from identity OTP DeliveryStatus (pending/delivered/terminal).
 */
final class NotificationDeliveryStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const DEAD = 'dead';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::DELIVERED,
            self::FAILED,
            self::DEAD,
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::DELIVERED || $status === self::DEAD;
    }

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::all(), true)) {
            throw new DomainRuleException('Invalid notification delivery status.');
        }
    }
}
