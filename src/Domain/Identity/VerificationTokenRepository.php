<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Notifications\SealedSecret;

interface VerificationTokenRepository
{
    public function findByHash(string $tokenHash): ?VerificationTokenRecord;

    public function findById(int $verificationTokenId): ?VerificationTokenRecord;

    public function findByIdForUpdate(int $verificationTokenId): ?VerificationTokenRecord;

    public function findCurrentByUserPurposeForUpdate(int $userId, string $purpose): ?VerificationTokenRecord;

    public function clearCurrentMarker(int $verificationTokenId): void;

    /**
     * Insert a pending current token with sealed columns null. Returns new id.
     */
    public function insertPendingCurrent(
        int $userId,
        string $purpose,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): int;

    public function updateSealedDelivery(int $verificationTokenId, SealedSecret $sealed, \DateTimeImmutable $now): void;

    /**
     * @return bool True when exactly one row was consumed
     */
    public function conditionalConsumeById(int $verificationTokenId, \DateTimeImmutable $now): bool;

    /**
     * Ambient-TX finalisation: pending → delivered + clear seal.
     *
     * @return bool True when rowCount === 1
     */
    public function markDelivered(
        int $verificationTokenId,
        ?string $providerMessageId,
        \DateTimeImmutable $now,
    ): bool;

    /**
     * Ambient-TX finalisation: pending → terminal + clear seal.
     *
     * @return bool True when rowCount === 1
     */
    public function markTerminal(
        int $verificationTokenId,
        string $redactedError,
        \DateTimeImmutable $now,
    ): bool;
}
