<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use DateTimeImmutable;

final class Payment
{
    public function __construct(
        public readonly int $paymentId,
        public readonly string $publicReference,
        public readonly int $applicationId,
        public readonly int $userId,
        public readonly string $provider,
        public readonly ?string $providerOrderId,
        public readonly ?string $providerPaymentId,
        public readonly int $baseFeeMinor,
        public readonly int $gstMinor,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $gstRatePercent,
        public readonly int $courseVersionId,
        public readonly int $batchId,
        public readonly ?string $feeOverrideApplied,
        public readonly string $status,
        public readonly ?string $failureCode,
        public readonly ?string $failureCategory,
        public readonly int $attemptNumber,
        public readonly string $idempotencyKey,
        public readonly int $rowVersion,
        public readonly ?int $successfulMarker,
        public readonly DateTimeImmutable $initiatedAt,
        public readonly ?DateTimeImmutable $providerOrderBoundAt,
        public readonly ?DateTimeImmutable $authorizedAt,
        public readonly ?DateTimeImmutable $capturedAt,
        public readonly ?DateTimeImmutable $failedAt,
        public readonly ?DateTimeImmutable $expiredAt,
        public readonly ?DateTimeImmutable $reconciledAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isInFlight(): bool
    {
        return PaymentStatus::isInFlight($this->status);
    }

    public function belongsToApplication(int $applicationId): bool
    {
        return $this->applicationId === $applicationId;
    }

    public function belongsToUser(int $userId): bool
    {
        return $this->userId === $userId;
    }
}
