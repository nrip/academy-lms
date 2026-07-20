<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Outbox;

use Academy\Domain\Outbox\OutboxMessage;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;
use PDOException;

final class PdoOutboxRepository implements OutboxRepository, OutboxWriter
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function enqueue(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): void {
        $this->insertPending(
            $eventType,
            $aggregateType,
            $aggregateId,
            $payload,
            $idempotencyKey,
            $correlationId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function insertPending(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $idempotencyKey,
        ?string $correlationId,
        \DateTimeImmutable $now,
    ): void {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO outbox_messages (
                event_type, aggregate_type, aggregate_id, payload, idempotency_key, status,
                attempt_count, available_at, locked_at, locked_by, lock_expires_at,
                published_at, dead_at, last_error, correlation_id, created_at, updated_at
            ) VALUES (
                :event_type, :aggregate_type, :aggregate_id, :payload, :idempotency_key, :status,
                0, :available_at, NULL, NULL, NULL,
                NULL, NULL, NULL, :correlation_id, :created_at, :updated_at
            )',
        );

        try {
            $ts = $now->format('Y-m-d H:i:s.u');
            $stmt->execute([
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'available_at' => $ts,
                'correlation_id' => $correlationId,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        } catch (PDOException $exception) {
            // Duplicate idempotency key → idempotent success
            if ($exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate')) {
                return;
            }
            throw $exception;
        }
    }

    public function claim(
        string $lockedBy,
        \DateTimeImmutable $now,
        int $leaseSeconds,
        int $limit = 10,
    ): array {
        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $nowStr = $now->format('Y-m-d H:i:s.u');
            $lockExpires = $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s.u');

            $select = $pdo->prepare(
                'SELECT outbox_message_id FROM outbox_messages
                 WHERE (
                    (status = :pending AND available_at <= :now1)
                    OR (status = :processing AND lock_expires_at IS NOT NULL AND lock_expires_at < :now2)
                    OR (status = :failed AND available_at <= :now3)
                 )
                 ORDER BY outbox_message_id ASC
                 LIMIT ' . (int) $limit . ' FOR UPDATE SKIP LOCKED',
            );
            $select->execute([
                'pending' => 'pending',
                'processing' => 'processing',
                'failed' => 'failed',
                'now1' => $nowStr,
                'now2' => $nowStr,
                'now3' => $nowStr,
            ]);
            $ids = array_map(static fn (array $r): int => (int) $r['outbox_message_id'], $select->fetchAll(PDO::FETCH_ASSOC));
            if ($ids === []) {
                $pdo->commit();

                return [];
            }

            $messages = [];
            foreach ($ids as $id) {
                $update = $pdo->prepare(
                    'UPDATE outbox_messages SET
                        status = :status,
                        attempt_count = attempt_count + 1,
                        locked_at = :locked_at,
                        locked_by = :locked_by,
                        lock_expires_at = :lock_expires_at,
                        updated_at = :updated_at
                     WHERE outbox_message_id = :id',
                );
                $update->execute([
                    'status' => 'processing',
                    'locked_at' => $nowStr,
                    'locked_by' => $lockedBy,
                    'lock_expires_at' => $lockExpires,
                    'updated_at' => $nowStr,
                    'id' => $id,
                ]);

                $fetch = $pdo->prepare(
                    'SELECT outbox_message_id, event_type, aggregate_type, aggregate_id, payload,
                            idempotency_key, status, attempt_count
                     FROM outbox_messages WHERE outbox_message_id = :id',
                );
                $fetch->execute(['id' => $id]);
                $row = $fetch->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    continue;
                }
                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $messages[] = new OutboxMessage(
                    (int) $row['outbox_message_id'],
                    (string) $row['event_type'],
                    (string) $row['aggregate_type'],
                    (string) $row['aggregate_id'],
                    $payload,
                    (string) $row['idempotency_key'],
                    (string) $row['status'],
                    (int) $row['attempt_count'],
                );
            }
            $pdo->commit();

            return $messages;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markPublished(int $id, \DateTimeImmutable $now): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE outbox_messages SET
                status = :status,
                published_at = :published_at,
                locked_at = NULL,
                locked_by = NULL,
                lock_expires_at = NULL,
                updated_at = :updated_at
             WHERE outbox_message_id = :id',
        );
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt->execute([
            'status' => 'published',
            'published_at' => $ts,
            'updated_at' => $ts,
            'id' => $id,
        ]);
    }

    public function markRetryOrDead(
        int $id,
        int $attemptCount,
        int $maxAttempts,
        string $error,
        \DateTimeImmutable $now,
        int $backoffSeconds,
    ): void {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        if ($attemptCount >= $maxAttempts) {
            $stmt = $pdo->prepare(
                'UPDATE outbox_messages SET
                    status = :status,
                    dead_at = :dead_at,
                    last_error = :last_error,
                    locked_at = NULL,
                    locked_by = NULL,
                    lock_expires_at = NULL,
                    updated_at = :updated_at
                 WHERE outbox_message_id = :id',
            );
            $stmt->execute([
                'status' => 'dead',
                'dead_at' => $ts,
                'last_error' => mb_substr($error, 0, 1024),
                'updated_at' => $ts,
                'id' => $id,
            ]);

            return;
        }

        $available = $now->modify('+' . $backoffSeconds . ' seconds')->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE outbox_messages SET
                status = :status,
                available_at = :available_at,
                last_error = :last_error,
                locked_at = NULL,
                locked_by = NULL,
                lock_expires_at = NULL,
                updated_at = :updated_at
             WHERE outbox_message_id = :id',
        );
        $stmt->execute([
            'status' => 'pending',
            'available_at' => $available,
            'last_error' => mb_substr($error, 0, 1024),
            'updated_at' => $ts,
            'id' => $id,
        ]);
    }
}
