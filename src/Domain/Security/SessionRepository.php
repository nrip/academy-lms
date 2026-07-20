<?php

declare(strict_types=1);

namespace Academy\Domain\Security;

interface SessionRepository
{
    public function findByTokenHash(string $tokenHash): ?SessionRecord;

    /**
     * @param array<string, mixed> $payload
     */
    public function create(
        string $tokenHash,
        ?string $csrfTokenHash,
        array $payload,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $absoluteExpiresAt,
        \DateTimeImmutable $idleExpiresAt,
        ?string $ipAddress,
        ?string $userAgentHash,
    ): SessionRecord;

    public function regenerate(
        int $sessionId,
        string $newTokenHash,
        ?string $newCsrfTokenHash,
        \DateTimeImmutable $now,
        \DateTimeImmutable $absoluteExpiresAt,
        \DateTimeImmutable $idleExpiresAt,
    ): void;

    public function updateCsrfHash(int $sessionId, string $csrfTokenHash): void;

    public function touch(
        int $sessionId,
        \DateTimeImmutable $lastActivityAt,
        \DateTimeImmutable $idleExpiresAt,
    ): void;

    public function revoke(int $sessionId, \DateTimeImmutable $revokedAt): void;

    public function bindUser(int $sessionId, int $userId): void;

    /**
     * @return int Number of deleted rows
     */
    public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int;
}
