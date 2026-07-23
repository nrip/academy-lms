<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

/**
 * Idempotency key for one logical notification per source event + channel + template.
 */
final class NotificationDeliveryIdempotency
{
    public static function key(int $outboxMessageId, string $channel, string $templateKey): string
    {
        return $outboxMessageId . ':' . $channel . ':' . $templateKey;
    }
}
