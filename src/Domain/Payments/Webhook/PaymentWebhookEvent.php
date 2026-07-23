<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

use DateTimeImmutable;

final class PaymentWebhookEvent
{
    public function __construct(
        public readonly int $webhookEventId,
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly ?string $providerOrderId,
        public readonly ?string $providerPaymentId,
        public readonly string $payloadDigest,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $providerStatus,
        public readonly ?int $capturedFlag,
        public readonly ?string $failureCode,
        public readonly ?string $failureCategory,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly DateTimeImmutable $signatureVerifiedAt,
        public readonly DateTimeImmutable $receivedAt,
        public readonly string $processingStatus,
        public readonly int $attemptCount,
        public readonly ?DateTimeImmutable $nextAttemptAt,
        public readonly ?string $failureCategoryProcessing,
        public readonly ?string $leaseOwner,
        public readonly ?string $leaseToken,
        public readonly ?DateTimeImmutable $leaseExpiresAt,
        public readonly int $rowVersion,
        public readonly ?DateTimeImmutable $processedAt,
        public readonly ?string $ignoreReason,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
