<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use DateTimeImmutable;

interface NotificationDeliveryRepository
{
    /**
     * Insert-or-return existing row for (outbox_message_id, channel, template_key).
     */
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
    ): NotificationDelivery;

    public function findById(int $notificationDeliveryId): ?NotificationDelivery;

    public function findByIdForUpdate(int $notificationDeliveryId): ?NotificationDelivery;

    public function findByIdempotency(
        int $outboxMessageId,
        string $channel,
        string $templateKey,
    ): ?NotificationDelivery;

    /**
     * Claim a pending/failed delivery for provider send. Returns null if lease lost or terminal.
     */
    public function claimForSend(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?NotificationDelivery;

    /**
     * @return bool False when lease/fencing lost
     */
    public function markDelivered(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        ?string $providerMessageId,
        DateTimeImmutable $now,
    ): bool;

    /**
     * @return bool False when lease/fencing lost
     */
    public function markFailedRetry(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        string $failureCategory,
        int $attemptCount,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Terminal failure before/without an active send lease (invalid recipient, missing context, etc.).
     *
     * @return bool False when row is already terminal or concurrently claimed
     */
    public function markDeadFromPrep(
        int $notificationDeliveryId,
        string $failureCategory,
        DateTimeImmutable $now,
    ): bool;

    /**
     * @return bool False when lease/fencing lost
     */
    public function markDead(
        int $notificationDeliveryId,
        string $leaseOwner,
        string $leaseToken,
        string $failureCategory,
        int $attemptCount,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Manual ops retry: reset dead/failed → pending when allowed.
     *
     * @return bool False when concurrent claim prevents update
     */
    public function requestManualRetry(
        int $notificationDeliveryId,
        DateTimeImmutable $now,
    ): bool;

    /**
     * @return list<NotificationDelivery>
     */
    public function listReadyForRetry(int $limit, DateTimeImmutable $now): array;

    /**
     * @return list<NotificationDelivery>
     */
    public function listForOps(int $limit = 50, int $offset = 0, ?string $status = null): array;

    public function countForOps(?string $status = null): int;
}
