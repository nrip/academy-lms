<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\TokenConfirmationContextRecord;
use Academy\Domain\Identity\TokenConfirmationContextRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoTokenConfirmationContextRepository implements TokenConfirmationContextRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insert(
        string $confirmationHash,
        int $verificationTokenId,
        int $userId,
        string $purpose,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): int {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO token_confirmation_contexts (
                confirmation_hash, verification_token_id, user_id, purpose, expires_at, consumed_at, created_at
            ) VALUES (
                :confirmation_hash, :verification_token_id, :user_id, :purpose, :expires_at, NULL, :created_at
            )',
        );
        $stmt->execute([
            'confirmation_hash' => $confirmationHash,
            'verification_token_id' => $verificationTokenId,
            'user_id' => $userId,
            'purpose' => $purpose,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findByHashForUpdate(string $confirmationHash): ?TokenConfirmationContextRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT token_confirmation_context_id, confirmation_hash, verification_token_id, user_id,
                    purpose, expires_at, consumed_at, created_at
             FROM token_confirmation_contexts
             WHERE confirmation_hash = :confirmation_hash
             LIMIT 1
             FOR UPDATE',
        );
        $stmt->execute(['confirmation_hash' => $confirmationHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function markConsumed(int $tokenConfirmationContextId, DateTimeImmutable $now): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE token_confirmation_contexts
             SET consumed_at = :consumed_at
             WHERE token_confirmation_context_id = :id
               AND consumed_at IS NULL',
        );
        $stmt->execute([
            'consumed_at' => $now->format('Y-m-d H:i:s.u'),
            'id' => $tokenConfirmationContextId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function deleteExpiredOrConsumedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM token_confirmation_contexts
             WHERE token_confirmation_context_id IN (
               SELECT id FROM (
                 SELECT token_confirmation_context_id AS id FROM token_confirmation_contexts
                 WHERE expires_at < :cutoff_expires OR (consumed_at IS NOT NULL AND consumed_at < :cutoff_consumed)
                 ORDER BY token_confirmation_context_id ASC
                 LIMIT :limit
               ) t
             )',
        );
        $cutoffStr = $cutoff->format('Y-m-d H:i:s.u');
        // Native prepares disallow duplicate named placeholders; bind both cutoffs explicitly.
        $stmt->bindValue('cutoff_expires', $cutoffStr);
        $stmt->bindValue('cutoff_consumed', $cutoffStr);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): TokenConfirmationContextRecord
    {
        $utc = new DateTimeZone('UTC');

        return new TokenConfirmationContextRecord(
            tokenConfirmationContextId: (int) $row['token_confirmation_context_id'],
            confirmationHash: (string) $row['confirmation_hash'],
            verificationTokenId: (int) $row['verification_token_id'],
            userId: (int) $row['user_id'],
            purpose: (string) $row['purpose'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at'], $utc),
            consumedAt: $row['consumed_at'] !== null
                ? new DateTimeImmutable((string) $row['consumed_at'], $utc)
                : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
        );
    }
}
