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

    /**
     * Bind user and copy users.auth_version onto the session (own short TX).
     *
     * @param array<string, mixed> $payloadMerge Merged into existing payload (e.g. auth_stage)
     */
    public function bindUser(int $sessionId, int $userId, int $authVersion, array $payloadMerge = []): void;

    /**
     * Merge allow-listed keys into an anonymous session payload (own short TX).
     * Must refuse authenticated sessions. Does not create authentication.
     *
     * @param array{pending_verification_user_id?: int, pending_verification_started_at?: string} $payloadMerge
     */
    public function mergeAnonymousPayload(int $sessionId, array $payloadMerge): void;

    /**
     * Physically revoke all non-revoked sessions for a user. Own short TX only.
     *
     * @return int Number of sessions revoked
     */
    public function revokeAllForUser(int $userId, \DateTimeImmutable $revokedAt): int;

    /**
     * @return int Number of deleted rows
     */
    public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int;
}
