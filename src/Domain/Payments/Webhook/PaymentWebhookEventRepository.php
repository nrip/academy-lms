<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

use DateTimeImmutable;

interface PaymentWebhookEventRepository
{
    /**
     * @return array{event: PaymentWebhookEvent, created: bool}
     */
    public function insertIdempotent(
        NormalizedWebhookEvent $normalized,
        DateTimeImmutable $signatureVerifiedAt,
        DateTimeImmutable $receivedAt,
    ): array;

    /**
     * @return list<PaymentWebhookEvent>
     */
    public function claimPending(
        string $workerId,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $limit,
    ): array;

    public function markProcessed(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    public function markIgnored(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        string $ignoreReason,
        DateTimeImmutable $now,
    ): bool;

    public function markRetryOrDead(
        int $webhookEventId,
        string $leaseOwner,
        string $leaseToken,
        int $expectedRowVersion,
        string $failureCategory,
        DateTimeImmutable $now,
        int $maxAttempts,
        int $backoffBaseSeconds,
        int $backoffCapSeconds,
    ): bool;

    public function findById(int $webhookEventId): ?PaymentWebhookEvent;

    /**
     * @return list<PaymentWebhookEvent>
     */
    public function listForFinance(?string $processingStatus, int $limit, int $offset): array;

    public function countForFinance(?string $processingStatus): int;
}
