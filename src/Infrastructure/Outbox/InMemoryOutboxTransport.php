<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Outbox;

use Academy\Domain\Outbox\OutboxTransport;

/**
 * Test-only transport. Must only be wired when APP_ENV=testing.
 */
final class InMemoryOutboxTransport implements OutboxTransport
{
    /** @var list<array{event_type: string, payload: array<string, mixed>, idempotency_key: string}> */
    public array $published = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function publish(string $eventType, array $payload, string $idempotencyKey): void
    {
        $this->published[] = [
            'event_type' => $eventType,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
