<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

interface OutboxTransport
{
    public function isConfigured(): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(string $eventType, array $payload, string $idempotencyKey): void;
}
