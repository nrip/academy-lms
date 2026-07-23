<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Notifications\NotificationDelivery;
use Academy\Domain\Notifications\NotificationDeliveryRepository;
use Academy\Domain\Notifications\NotificationDeliveryStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class PdoNotificationDeliveryRepository implements NotificationDeliveryRepository
{
    private const COLUMNS = 'notification_delivery_id, outbox_message_id, source_event_type, user_id, channel,
        template_key, template_version, recipient_hash, recipient_masked, status, attempt_count,
        next_attempt_at, lease_owner, lease_token, lease_expires_at, provider_message_id,
        failure_category, delivered_at, dead_at, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function ensurePending(
        int $outboxMessageId,
        string $sourceEventType,
        int $userId,
        string $channel,
        string $templateKey,
        int $templateVersion,
        string $recipientHash,
        string $recipientMasked,
        DateTimeImmutable $now,
    ): NotificationDelivery {
        $pdo = $this->connections->connection();
        $existing = $this->findByIdempotency($outboxMessageId, $channel, $templateKey);
        if ($existing !== null) {
            return $existing;
        }

        $sql = 'INSERT INTO notification_deliveries (
            outbox_message_id, source_event_type, user_id, channel, template_key, template_version,
            recipient_hash, recipient_masked, status, attempt_count, next_attempt_at,
            created_at, updated_at
        ) VALUES (
            :outbox_message_id, :source_event_type, :user_id, :channel, :template_key, :template_version,
            :recipient_hash, :recipient_masked, :status, 0, NULL,
            :created_at, :updated_at
        )';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'outbox_message_id' => $outboxMessageId,
                'source_event_type' => $sourceEventType,
                'user_id' => $userId,
                'channel' => $channel,
                'template_key' => $templateKey,
                'template_version' => $templateVersion,
                'recipient_hash' => $recipientHash,
                'recipient_masked' => $recipientMasked,
                'status' => NotificationDeliveryStatus::PENDING,
                'created_at' => $this->format($now),
                'updated_at' => $this->format($now),
            ]);
        } catch (PDOException $exception) {
            $driverCode = is_array($exception->errorInfo) && isset($exception->errorInfo[1])
                ? (int) $exception->errorInfo[1]
                : 0;
            if ($driverCode !== 1062) {
                throw $exception;
            }
        }

        $row = $this->findByIdempotency($outboxMessageId, $channel, $templateKey);
        if ($row === null) {
            throw new \RuntimeException('Failed to ensure notification delivery row.');
        }

        return $row;
    }

    public function findById(int $notificationDeliveryId): ?NotificationDelivery
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM notification_deliveries WHERE notification_delivery_id = :id');
        $stmt->execute(['id' => $notificationDeliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findByIdForUpdate(int $notificationDeliveryId): ?NotificationDelivery
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM notification_deliveries WHERE notification_delivery_id = :id FOR UPDATE',
        );
        $stmt->execute(['id' => $notificationDeliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findByIdempotency(
        int $outboxMessageId,
        string $channel,
        string $templateKey,
    ): ?NotificationDelivery {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM notification_deliveries
             WHERE outbox_message_id = :outbox_message_id AND channel = :channel AND template_key = :template_key',
        );
        $stmt->execute([
            'outbox_message_id' => $outboxMessageId,
            'channel' => $channel,
            'template_key' => $templateKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function claimForSend(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?NotificationDelivery {
        $pdo = $this->connections->connection();
        $expires = $now->modify('+' . $leaseSeconds . ' seconds');
        $stmt = $pdo->prepare(
            'UPDATE notification_deliveries
             SET status = :processing,
                 attempt_count = attempt_count + 1,
                 lease_owner = :lease_owner,
                 lease_token = :lease_token,
                 lease_expires_at = :lease_expires_at,
                 updated_at = :updated_at
             WHERE notification_delivery_id = :id
               AND status IN (:status_pending, :status_failed)
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now_check)
               AND (lease_expires_at IS NULL OR lease_expires_at < :now_lease)',
        );
        $stmt->execute([
            'processing' => NotificationDeliveryStatus::PROCESSING,
            'lease_owner' => $leaseOwner,
            'lease_token' => $leaseToken,
            'lease_expires_at' => $this->format($expires),
            'updated_at' => $this->format($now),
            'id' => $notificationDeliveryId,
            'status_pending' => NotificationDeliveryStatus::PENDING,
            'status_failed' => NotificationDeliveryStatus::FAILED,
            'now_check' => $this->format($now),
            'now_lease' => $this->format($now),
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findById($notificationDeliveryId);
    }

    public function markDelivered(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        ?string $providerMessageId,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE notification_deliveries
             SET status = :delivered,
                 provider_message_id = :provider_message_id,
                 delivered_at = :delivered_at,
                 failure_category = NULL,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 next_attempt_at = NULL,
                 updated_at = :updated_at
             WHERE notification_delivery_id = :id
               AND status = :processing
               AND lease_owner = :lease_owner
               AND lease_token = :lease_token
               AND lease_expires_at >= :now',
        );
        $stmt->execute([
            'delivered' => NotificationDeliveryStatus::DELIVERED,
            'provider_message_id' => $providerMessageId,
            'delivered_at' => $this->format($now),
            'updated_at' => $this->format($now),
            'id' => $notificationDeliveryId,
            'processing' => NotificationDeliveryStatus::PROCESSING,
            'lease_owner' => $leaseOwner,
            'lease_token' => $leaseToken,
            'now' => $this->format($now),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markFailedRetry(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        string $failureCategory,
        int $attemptCount,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE notification_deliveries
             SET status = :failed,
                 failure_category = :failure_category,
                 attempt_count = :attempt_count,
                 next_attempt_at = :next_attempt_at,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 updated_at = :updated_at
             WHERE notification_delivery_id = :id
               AND status = :processing
               AND lease_owner = :lease_owner
               AND lease_token = :lease_token
               AND lease_expires_at >= :now',
        );
        $stmt->execute([
            'failed' => NotificationDeliveryStatus::FAILED,
            'failure_category' => $failureCategory,
            'attempt_count' => $attemptCount,
            'next_attempt_at' => $this->format($nextAttemptAt),
            'updated_at' => $this->format($now),
            'id' => $notificationDeliveryId,
            'processing' => NotificationDeliveryStatus::PROCESSING,
            'lease_owner' => $leaseOwner,
            'lease_token' => $leaseToken,
            'now' => $this->format($now),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markDeadFromPrep(
        int $notificationDeliveryId,
        string $failureCategory,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE notification_deliveries
             SET status = :dead,
                 failure_category = :failure_category,
                 dead_at = :dead_at,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 next_attempt_at = NULL,
                 updated_at = :updated_at
             WHERE notification_delivery_id = :id
               AND status IN (:pending, :failed)
               AND (lease_expires_at IS NULL OR lease_expires_at < :now)',
        );
        $stmt->execute([
            'dead' => NotificationDeliveryStatus::DEAD,
            'failure_category' => $failureCategory,
            'dead_at' => $this->format($now),
            'updated_at' => $this->format($now),
            'id' => $notificationDeliveryId,
            'pending' => NotificationDeliveryStatus::PENDING,
            'failed' => NotificationDeliveryStatus::FAILED,
            'now' => $this->format($now),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markDead(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        string $failureCategory,
        int $attemptCount,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE notification_deliveries
             SET status = :dead,
                 failure_category = :failure_category,
                 attempt_count = :attempt_count,
                 dead_at = :dead_at,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 next_attempt_at = NULL,
                 updated_at = :updated_at
             WHERE notification_delivery_id = :id
               AND status = :processing
               AND lease_owner = :lease_owner
               AND lease_token = :lease_token
               AND lease_expires_at >= :now',
        );
        $stmt->execute([
            'dead' => NotificationDeliveryStatus::DEAD,
            'failure_category' => $failureCategory,
            'attempt_count' => $attemptCount,
            'dead_at' => $this->format($now),
            'updated_at' => $this->format($now),
            'id' => $notificationDeliveryId,
            'processing' => NotificationDeliveryStatus::PROCESSING,
            'lease_owner' => $leaseOwner,
            'lease_token' => $leaseToken,
            'now' => $this->format($now),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function requestManualRetry(int $notificationDeliveryId, DateTimeImmutable $now): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE notification_deliveries
             SET status = :pending,
                 next_attempt_at = NULL,
                 failure_category = NULL,
                 lease_owner = NULL,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 dead_at = NULL,
                 updated_at = :updated_at
             WHERE notification_delivery_id = :id
               AND status IN (:failed, :dead)
               AND (lease_expires_at IS NULL OR lease_expires_at < :now)',
        );
        $stmt->execute([
            'pending' => NotificationDeliveryStatus::PENDING,
            'updated_at' => $this->format($now),
            'id' => $notificationDeliveryId,
            'failed' => NotificationDeliveryStatus::FAILED,
            'dead' => NotificationDeliveryStatus::DEAD,
            'now' => $this->format($now),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function listReadyForRetry(int $limit, DateTimeImmutable $now): array
    {
        $pdo = $this->connections->connection();
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM notification_deliveries
             WHERE status IN (:pending, :failed)
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
               AND (lease_expires_at IS NULL OR lease_expires_at < :now2)
             ORDER BY notification_delivery_id ASC
             LIMIT ' . $limit,
        );
        $stmt->execute([
            'pending' => NotificationDeliveryStatus::PENDING,
            'failed' => NotificationDeliveryStatus::FAILED,
            'now' => $this->format($now),
            'now2' => $this->format($now),
        ]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): NotificationDelivery => $this->map($row), $rows);
    }

    public function listForOps(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $pdo = $this->connections->connection();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        if ($status !== null) {
            NotificationDeliveryStatus::assertValid($status);
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLUMNS . ' FROM notification_deliveries
                 WHERE status = :status
                 ORDER BY notification_delivery_id DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset,
            );
            $stmt->execute(['status' => $status]);
        } else {
            $stmt = $pdo->query(
                'SELECT ' . self::COLUMNS . ' FROM notification_deliveries
                 ORDER BY notification_delivery_id DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset,
            );
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): NotificationDelivery => $this->map($row), $rows);
    }

    public function countForOps(?string $status = null): int
    {
        $pdo = $this->connections->connection();
        if ($status !== null) {
            NotificationDeliveryStatus::assertValid($status);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM notification_deliveries WHERE status = :status');
            $stmt->execute(['status' => $status]);
        } else {
            $stmt = $pdo->query('SELECT COUNT(*) FROM notification_deliveries');
        }

        return (int) ($stmt === false ? 0 : $stmt->fetchColumn());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): NotificationDelivery
    {
        return new NotificationDelivery(
            notificationDeliveryId: (int) $row['notification_delivery_id'],
            outboxMessageId: (int) $row['outbox_message_id'],
            sourceEventType: (string) $row['source_event_type'],
            userId: (int) $row['user_id'],
            channel: (string) $row['channel'],
            templateKey: (string) $row['template_key'],
            templateVersion: (int) $row['template_version'],
            recipientHash: (string) $row['recipient_hash'],
            recipientMasked: (string) $row['recipient_masked'],
            status: (string) $row['status'],
            attemptCount: (int) $row['attempt_count'],
            nextAttemptAt: $this->parseNullable($row['next_attempt_at'] ?? null),
            leaseOwner: $row['lease_owner'] !== null ? (string) $row['lease_owner'] : null,
            leaseToken: $row['lease_token'] !== null ? (string) $row['lease_token'] : null,
            leaseExpiresAt: $this->parseNullable($row['lease_expires_at'] ?? null),
            providerMessageId: $row['provider_message_id'] !== null ? (string) $row['provider_message_id'] : null,
            failureCategory: $row['failure_category'] !== null ? (string) $row['failure_category'] : null,
            deliveredAt: $this->parseNullable($row['delivered_at'] ?? null),
            deadAt: $this->parseNullable($row['dead_at'] ?? null),
            createdAt: $this->parse((string) $row['created_at']),
            updatedAt: $this->parse((string) $row['updated_at']),
        );
    }

    private function format(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function parseNullable(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->parse((string) $value);
    }
}
