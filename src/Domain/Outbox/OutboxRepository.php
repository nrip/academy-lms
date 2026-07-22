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

    /**
     * @param list<string> $eventTypes
     * @return list<OutboxMessage>
     */
    public function claimByEventTypes(
        string $lockedBy,
        \DateTimeImmutable $now,
        int $leaseSeconds,
        array $eventTypes,
        int $limit = 10,
    ): array;

    /**
     * @param list<string> $eventTypes
     * @return list<OutboxMessage>
     */
    public function claimExcludingEventTypes(
        string $lockedBy,
        \DateTimeImmutable $now,
        int $leaseSeconds,
        array $eventTypes,
        int $limit = 10,
    ): array;

    /**
     * @return bool True when this claim still owns the message and the update applied
     */
    public function markPublished(
        int $id,
        string $lockedBy,
        string $claimToken,
        \DateTimeImmutable $now,
    ): bool;

    /**
     * @return bool True when this claim still owns the message and the update applied
     */
    public function markRetryOrDead(
        int $id,
        string $lockedBy,
        string $claimToken,
        int $attemptCount,
        int $maxAttempts,
        string $error,
        \DateTimeImmutable $now,
        int $backoffSeconds,
    ): bool;
}
