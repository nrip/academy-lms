<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use Academy\Domain\Exception\DomainRuleException;

final class DeliveryStatus
{
    public const PENDING = 'pending';
    public const DELIVERED = 'delivered';
    public const TERMINAL = 'terminal';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::DELIVERED, self::TERMINAL];
    }

    public static function assertTransition(string $from, string $to): void
    {
        if ($from !== self::PENDING) {
            throw new DomainRuleException('Delivery status may only leave pending once.');
        }
        if ($to !== self::DELIVERED && $to !== self::TERMINAL) {
            throw new DomainRuleException('Invalid delivery status transition.');
        }
    }
}
