<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Notifications\SealedSecret;

interface VerificationChallengeRepository
{
    public function findById(int $verificationChallengeId): ?VerificationChallengeRecord;

    public function findByIdForUpdate(int $verificationChallengeId): ?VerificationChallengeRecord;

    public function findCurrentByUserChannelForUpdate(int $userId, string $channel): ?VerificationChallengeRecord;

    public function clearCurrentMarker(int $verificationChallengeId): void;

    public function insertPendingCurrent(
        int $userId,
        string $channel,
        string $destinationHmac,
        string $otpBindingNonce,
        string $otpHmac,
        \DateTimeImmutable $expiresAt,
        int $maxAttempts,
        \DateTimeImmutable $now,
    ): int;

    public function updateSealedDelivery(int $verificationChallengeId, SealedSecret $sealed, \DateTimeImmutable $now): void;

    public function incrementAttempt(int $verificationChallengeId): int;

    /**
     * @return bool True when exactly one row was consumed
     */
    public function conditionalConsumeById(int $verificationChallengeId, \DateTimeImmutable $now): bool;

    public function markDelivered(
        int $verificationChallengeId,
        ?string $providerMessageId,
        \DateTimeImmutable $now,
    ): bool;

    public function markTerminal(
        int $verificationChallengeId,
        string $redactedError,
        \DateTimeImmutable $now,
    ): bool;
}
