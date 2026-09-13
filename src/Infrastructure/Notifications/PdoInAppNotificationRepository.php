<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Notifications;

use Academy\Domain\Notifications\InAppNotification;
use Academy\Domain\Notifications\InAppNotificationRepository;
use Academy\Domain\Notifications\LearnerInboxCopy;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoInAppNotificationRepository implements InAppNotificationRepository
{
    private const COLUMNS = 'in_app_notification_id, user_id, outbox_message_id, source_event_type,
        title, body, href, read_at, created_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function record(LearnerInboxCopy $copy, DateTimeImmutable $now): void
    {
        if ($copy->userId <= 0 || $copy->outboxMessageId <= 0 || $copy->title === '') {
            return;
        }

        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO in_app_notifications (
                user_id, outbox_message_id, source_event_type, title, body, href, read_at, created_at
             ) VALUES (
                :user_id, :outbox_message_id, :source_event_type, :title, :body, :href, NULL, :created_at
             )
             ON DUPLICATE KEY UPDATE in_app_notification_id = in_app_notification_id',
        );
        $stmt->execute([
            'user_id' => $copy->userId,
            'outbox_message_id' => $copy->outboxMessageId,
            'source_event_type' => $copy->eventType,
            'title' => $copy->title,
            'body' => $copy->body,
            'href' => $copy->href,
            'created_at' => $this->format($now),
        ]);
    }

    public function listForUser(int $userId, int $limit): array
    {
        $limit = max(1, min($limit, 50));
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM in_app_notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, in_app_notification_id DESC
             LIMIT ' . $limit,
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    public function countUnread(int $userId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM in_app_notifications
             WHERE user_id = :user_id AND read_at IS NULL',
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function findForUser(int $id, int $userId): ?InAppNotification
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM in_app_notifications
             WHERE in_app_notification_id = :id AND user_id = :user_id',
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function markRead(int $id, int $userId, DateTimeImmutable $now): bool
    {
        $existing = $this->findForUser($id, $userId);
        if ($existing === null) {
            return false;
        }
        if ($existing->readAt !== null) {
            return true;
        }

        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE in_app_notifications
             SET read_at = :read_at
             WHERE in_app_notification_id = :id AND user_id = :user_id AND read_at IS NULL',
        );
        $stmt->execute([
            'read_at' => $this->format($now),
            'id' => $id,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): InAppNotification
    {
        return new InAppNotification(
            id: (int) $row['in_app_notification_id'],
            userId: (int) $row['user_id'],
            outboxMessageId: (int) $row['outbox_message_id'],
            eventType: (string) $row['source_event_type'],
            title: (string) $row['title'],
            body: (string) $row['body'],
            href: (string) $row['href'],
            readAt: $this->parseNullable($row['read_at']),
            createdAt: $this->parse((string) $row['created_at']),
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
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $this->parse($value);
    }
}
