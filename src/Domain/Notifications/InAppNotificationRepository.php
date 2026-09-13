<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use DateTimeImmutable;

interface InAppNotificationRepository
{
    /**
     * Insert once per outbox message. A retry must not create a second row.
     */
    public function record(LearnerInboxCopy $copy, DateTimeImmutable $now): void;

    /**
     * @return list<InAppNotification>
     */
    public function listForUser(int $userId, int $limit): array;

    public function countUnread(int $userId): int;

    public function findForUser(int $id, int $userId): ?InAppNotification;

    /**
     * True when the row belongs to the user (already-read is still success).
     */
    public function markRead(int $id, int $userId, DateTimeImmutable $now): bool;
}
