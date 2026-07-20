<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

final class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $eventType,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly array $payload,
        public readonly string $idempotencyKey,
        public readonly string $status,
        public readonly int $attemptCount,
        public readonly string $lockedBy,
        public readonly string $claimToken,
    ) {
    }
}
