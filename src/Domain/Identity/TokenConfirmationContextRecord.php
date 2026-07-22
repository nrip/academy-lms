<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

final class TokenConfirmationContextRecord
{
    public function __construct(
        public readonly int $tokenConfirmationContextId,
        public readonly string $confirmationHash,
        public readonly int $verificationTokenId,
        public readonly int $userId,
        public readonly string $purpose,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $consumedAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $this->expiresAt > $now;
    }
}
