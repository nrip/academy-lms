<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

final class VerificationChallengeRecord
{
    public function __construct(
        public readonly int $verificationChallengeId,
        public readonly int $userId,
        public readonly string $channel,
        public readonly string $destinationHmac,
        public readonly string $otpBindingNonce,
        public readonly string $otpHmac,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $attemptCount,
        public readonly int $maxAttempts,
        public readonly ?\DateTimeImmutable $consumedAt,
        public readonly ?int $currentMarker,
        public readonly ?string $otpDeliveryCiphertext,
        public readonly ?string $otpDeliveryNonce,
        public readonly ?int $otpDeliveryKeyVersion,
        public readonly string $deliveryStatus,
        public readonly ?\DateTimeImmutable $deliveredAt,
        public readonly ?string $providerMessageId,
        public readonly ?\DateTimeImmutable $terminalAt,
        public readonly ?string $deliveryLastError,
        public readonly ?\DateTimeImmutable $otpDeliveryClearedAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastSentAt,
    ) {
    }

    public function isCurrent(): bool
    {
        return $this->currentMarker === 1;
    }
}
