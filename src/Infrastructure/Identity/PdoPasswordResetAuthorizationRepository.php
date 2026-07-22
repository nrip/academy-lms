<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\PasswordResetAuthorizationRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoPasswordResetAuthorizationRepository implements PasswordResetAuthorizationRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insert(
        int $userId,
        int $verificationTokenId,
        string $authorizationHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): int {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO password_reset_authorizations (
                user_id, verification_token_id, authorization_hash, expires_at, consumed_at, created_at
            ) VALUES (
                :user_id, :verification_token_id, :authorization_hash, :expires_at, NULL, :created_at
            )',
        );
        $stmt->execute([
            'user_id' => $userId,
            'verification_token_id' => $verificationTokenId,
            'authorization_hash' => $authorizationHash,
            'expires_at' => $this->formatUtc($expiresAt),
            'created_at' => $this->formatUtc($now),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findByHashForUpdate(string $authorizationHash): ?array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT password_reset_authorization_id, user_id, verification_token_id,
                    authorization_hash, expires_at, consumed_at
             FROM password_reset_authorizations
             WHERE authorization_hash = :authorization_hash
             LIMIT 1
             FOR UPDATE',
        );
        $stmt->execute(['authorization_hash' => $authorizationHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'password_reset_authorization_id' => (int) $row['password_reset_authorization_id'],
            'user_id' => (int) $row['user_id'],
            'verification_token_id' => (int) $row['verification_token_id'],
            'authorization_hash' => (string) $row['authorization_hash'],
            'expires_at' => (string) $row['expires_at'],
            'consumed_at' => $row['consumed_at'] !== null ? (string) $row['consumed_at'] : null,
        ];
    }

    public function markConsumed(int $authorizationId, DateTimeImmutable $now): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE password_reset_authorizations
             SET consumed_at = :consumed_at
             WHERE password_reset_authorization_id = :id
               AND consumed_at IS NULL',
        );
        $stmt->execute([
            'consumed_at' => $this->formatUtc($now),
            'id' => $authorizationId,
        ]);

        return $stmt->rowCount() === 1;
    }

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
    public function isUsable(?array $row, DateTimeImmutable $now): bool
    {
        if ($row === null) {
            return false;
        }
        if ($row['consumed_at'] !== null) {
            return false;
        }
        $expires = new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC'));

        return $expires > $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function formatUtc(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
