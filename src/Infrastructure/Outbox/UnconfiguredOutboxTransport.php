<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Outbox;

use Academy\Domain\Outbox\OutboxTransport;

/**
 * Unconfigured transport — relay must not be scheduled when this is active.
 */
final class UnconfiguredOutboxTransport implements OutboxTransport
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function publish(string $eventType, array $payload, string $idempotencyKey): void
    {
        throw new \RuntimeException('Outbox transport is not configured.');
    }
}
