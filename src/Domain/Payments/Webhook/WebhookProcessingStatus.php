<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

final class WebhookProcessingStatus
{
    public const RECEIVED = 'received';
    public const PROCESSING = 'processing';
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const FAILED = 'failed';
    public const DEAD = 'dead';

    /** @var list<string> */
    public const ALL = [
        self::RECEIVED,
        self::PROCESSING,
        self::PROCESSED,
        self::IGNORED,
        self::FAILED,
        self::DEAD,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Unknown webhook processing status.');
        }
    }
}
