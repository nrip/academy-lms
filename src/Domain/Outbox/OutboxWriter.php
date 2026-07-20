<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

interface OutboxWriter
{
    /**
     * @param array<string, mixed> $payload Allow-listed event payload only
     */
    public function enqueue(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): void;
}
