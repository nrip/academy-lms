<?php

declare(strict_types=1);

namespace Academy\Domain\Security;

final class SessionRecord
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly string $tokenHash,
        public readonly ?int $userId,
        public readonly array $payload,
        public readonly ?string $csrfTokenHash,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $lastActivityAt,
        public readonly \DateTimeImmutable $absoluteExpiresAt,
        public readonly \DateTimeImmutable $idleExpiresAt,
        public readonly ?\DateTimeImmutable $revokedAt,
        public readonly ?int $authVersion = null,
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        if ($this->revokedAt !== null) {
            return true;
        }

        return $now > $this->absoluteExpiresAt || $now > $this->idleExpiresAt;
    }
}
