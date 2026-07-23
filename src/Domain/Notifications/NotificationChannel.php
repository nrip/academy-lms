<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

final class NotificationChannel
{
    public const EMAIL = 'email';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EMAIL];
    }
}
