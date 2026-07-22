<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

interface TokenConfirmationContextRepository
{
    public function insert(
        string $confirmationHash,
        int $verificationTokenId,
        int $userId,
        string $purpose,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): int;

    public function findByHashForUpdate(string $confirmationHash): ?TokenConfirmationContextRecord;

    /**
     * @return bool True when rowCount === 1
     */
    public function markConsumed(int $tokenConfirmationContextId, \DateTimeImmutable $now): bool;

    /**
     * Physically delete expired or aged-consumed contexts only.
     * Never deletes unexpired unconsumed rows.
     */
    public function deleteExpiredOrConsumedBefore(\DateTimeImmutable $cutoff, int $limit): int;
}
