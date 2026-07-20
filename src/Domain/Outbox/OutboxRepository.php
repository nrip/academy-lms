<?php

declare(strict_types=1);

namespace Academy\Domain\Outbox;

interface OutboxRepository
{
    /**
     * @param array<string, mixed> $payload
     */
    public function insertPending(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $idempotencyKey,
        ?string $correlationId,
        \DateTimeImmutable $now,
    ): void;

    /**
     * @return list<OutboxMessage>
     */
    public function claim(
        string $lockedBy,
        \DateTimeImmutable $now,
        int $leaseSeconds,
        int $limit = 10,
    ): array;

    public function markPublished(int $id, \DateTimeImmutable $now): void;

    public function markRetryOrDead(
        int $id,
        int $attemptCount,
        int $maxAttempts,
        string $error,
        \DateTimeImmutable $now,
        int $backoffSeconds,
    ): void;
}
