<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use DateTimeImmutable;

final class InAppNotification
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $outboxMessageId,
        public readonly string $eventType,
        public readonly string $title,
        public readonly string $body,
        public readonly string $href,
        public readonly ?DateTimeImmutable $readAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
