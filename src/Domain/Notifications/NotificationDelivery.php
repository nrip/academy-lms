<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use DateTimeImmutable;

final class NotificationDelivery
{
    public function __construct(
        public readonly int $notificationDeliveryId,
        public readonly int $outboxMessageId,
        public readonly string $sourceEventType,
        public readonly int $userId,
        public readonly string $channel,
        public readonly string $templateKey,
        public readonly int $templateVersion,
        public readonly string $recipientHash,
        public readonly string $recipientMasked,
        public readonly string $status,
        public readonly int $attemptCount,
        public readonly ?DateTimeImmutable $nextAttemptAt,
        public readonly ?string $leaseOwner,
        public readonly ?string $leaseToken,
        public readonly ?DateTimeImmutable $leaseExpiresAt,
        public readonly ?string $providerMessageId,
        public readonly ?string $failureCategory,
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly ?DateTimeImmutable $deadAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
