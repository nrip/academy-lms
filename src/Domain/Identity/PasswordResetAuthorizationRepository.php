<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use DateTimeImmutable;

interface PasswordResetAuthorizationRepository
{
    public function insert(
        int $userId,
        int $verificationTokenId,
        string $authorizationHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): int;

    /**
     * @return array{
     *   password_reset_authorization_id: int,
     *   user_id: int,
     *   verification_token_id: int,
     *   authorization_hash: string,
     *   expires_at: string,
     *   consumed_at: ?string
     * }|null
     */
    public function findByHashForUpdate(string $authorizationHash): ?array;

    public function markConsumed(int $authorizationId, DateTimeImmutable $now): bool;

    /**
     * @param array{
     *   password_reset_authorization_id: int,
     *   user_id: int,
     *   verification_token_id: int,
     *   authorization_hash: string,
     *   expires_at: string,
     *   consumed_at: ?string
     * }|null $row
     */
    public function isUsable(?array $row, DateTimeImmutable $now): bool;
}
