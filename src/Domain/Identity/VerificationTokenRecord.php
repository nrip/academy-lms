<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

final class VerificationTokenRecord
{
    public function __construct(
        public readonly int $verificationTokenId,
        public readonly int $userId,
        public readonly string $purpose,
        public readonly string $tokenHash,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $consumedAt,
        public readonly ?int $currentMarker,
        public readonly ?string $deliveryCiphertext,
        public readonly ?string $deliveryNonce,
        public readonly ?int $deliveryKeyVersion,
        public readonly string $deliveryStatus,
        public readonly ?\DateTimeImmutable $deliveredAt,
        public readonly ?string $providerMessageId,
        public readonly ?\DateTimeImmutable $terminalAt,
        public readonly ?string $deliveryLastError,
        public readonly ?\DateTimeImmutable $deliveryClearedAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastSentAt,
    ) {
    }

    public function isCurrent(): bool
    {
        return $this->currentMarker === 1;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->isCurrent()
            && $this->consumedAt === null
            && $this->expiresAt > $now;
    }
}
