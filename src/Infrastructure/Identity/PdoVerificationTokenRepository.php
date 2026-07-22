<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\VerificationTokenRecord;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Notifications\SealedSecret;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoVerificationTokenRepository implements VerificationTokenRepository
{
    private const SELECT_COLUMNS = 'verification_token_id, user_id, purpose, token_hash, expires_at, consumed_at,
                current_marker, delivery_ciphertext, delivery_nonce, delivery_key_version, delivery_status,
                delivered_at, provider_message_id, terminal_at, delivery_last_error, delivery_cleared_at,
                created_at, last_sent_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findByHash(string $tokenHash): ?VerificationTokenRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_tokens
             WHERE token_hash = :token_hash
             LIMIT 1',
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findById(int $verificationTokenId): ?VerificationTokenRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_tokens
             WHERE verification_token_id = :id
             LIMIT 1',
        );
        $stmt->execute(['id' => $verificationTokenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findByIdForUpdate(int $verificationTokenId): ?VerificationTokenRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_tokens
             WHERE verification_token_id = :id
             LIMIT 1
             FOR UPDATE',
        );
        $stmt->execute(['id' => $verificationTokenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findCurrentByUserPurposeForUpdate(int $userId, string $purpose): ?VerificationTokenRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_tokens
             WHERE user_id = :user_id
               AND purpose = :purpose
               AND current_marker = 1
             LIMIT 1
             FOR UPDATE',
        );
        $stmt->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function clearCurrentMarker(int $verificationTokenId): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE verification_tokens
             SET current_marker = NULL
             WHERE verification_token_id = :id',
        );
        $stmt->execute(['id' => $verificationTokenId]);
    }

    public function insertPendingCurrent(
        int $userId,
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): int {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO verification_tokens (
                user_id, purpose, token_hash, expires_at, consumed_at, current_marker,
                delivery_ciphertext, delivery_nonce, delivery_key_version, delivery_status,
                delivered_at, provider_message_id, terminal_at, delivery_last_error, delivery_cleared_at,
                created_at, last_sent_at
            ) VALUES (
                :user_id, :purpose, :token_hash, :expires_at, NULL, 1,
                NULL, NULL, NULL, :delivery_status,
                NULL, NULL, NULL, NULL, NULL,
                :created_at, NULL
            )',
        );
        $stmt->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            'delivery_status' => 'pending',
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateSealedDelivery(int $verificationTokenId, SealedSecret $sealed, DateTimeImmutable $now): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE verification_tokens SET
                delivery_ciphertext = :ciphertext,
                delivery_nonce = :nonce,
                delivery_key_version = :key_version,
                last_sent_at = :last_sent_at
             WHERE verification_token_id = :id',
        );
        $stmt->execute([
            'ciphertext' => $sealed->ciphertext,
            'nonce' => $sealed->nonce,
            'key_version' => $sealed->keyVersion,
            'last_sent_at' => $now->format('Y-m-d H:i:s.u'),
            'id' => $verificationTokenId,
        ]);
    }

    public function conditionalConsumeById(int $verificationTokenId, DateTimeImmutable $now): bool
    {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE verification_tokens SET
                consumed_at = :consumed_at,
                current_marker = NULL,
                delivery_ciphertext = NULL,
                delivery_nonce = NULL,
                delivery_key_version = NULL,
                delivery_cleared_at = CASE
                    WHEN delivery_ciphertext IS NOT NULL THEN :cleared_at
                    ELSE delivery_cleared_at
                END
             WHERE verification_token_id = :id
               AND current_marker = 1
               AND consumed_at IS NULL
               AND expires_at > :now',
        );
        $stmt->execute([
            'consumed_at' => $ts,
            'cleared_at' => $ts,
            'id' => $verificationTokenId,
            'now' => $ts,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markDelivered(
        int $verificationTokenId,
        ?string $providerMessageId,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE verification_tokens SET
                delivery_status = :delivered_status,
                delivered_at = :delivered_at,
                provider_message_id = :provider_message_id,
                delivery_ciphertext = NULL,
                delivery_nonce = NULL,
                delivery_key_version = NULL,
                delivery_cleared_at = :cleared_at
             WHERE verification_token_id = :id
               AND delivery_status = :pending_status',
        );
        $stmt->execute([
            'delivered_status' => 'delivered',
            'delivered_at' => $ts,
            'provider_message_id' => $providerMessageId,
            'cleared_at' => $ts,
            'id' => $verificationTokenId,
            'pending_status' => 'pending',
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markTerminal(
        int $verificationTokenId,
        string $redactedError,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE verification_tokens SET
                delivery_status = :terminal_status,
                terminal_at = :terminal_at,
                delivery_last_error = :delivery_last_error,
                delivery_ciphertext = NULL,
                delivery_nonce = NULL,
                delivery_key_version = NULL,
                delivery_cleared_at = :cleared_at
             WHERE verification_token_id = :id
               AND delivery_status = :pending_status',
        );
        $stmt->execute([
            'terminal_status' => 'terminal',
            'terminal_at' => $ts,
            'delivery_last_error' => mb_substr($redactedError, 0, 512),
            'cleared_at' => $ts,
            'id' => $verificationTokenId,
            'pending_status' => 'pending',
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): VerificationTokenRecord
    {
        $utc = new DateTimeZone('UTC');

        return new VerificationTokenRecord(
            verificationTokenId: (int) $row['verification_token_id'],
            userId: (int) $row['user_id'],
            purpose: (string) $row['purpose'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at'], $utc),
            consumedAt: $row['consumed_at'] !== null
                ? new DateTimeImmutable((string) $row['consumed_at'], $utc)
                : null,
            currentMarker: $row['current_marker'] !== null ? (int) $row['current_marker'] : null,
            deliveryCiphertext: $row['delivery_ciphertext'] !== null ? (string) $row['delivery_ciphertext'] : null,
            deliveryNonce: $row['delivery_nonce'] !== null ? (string) $row['delivery_nonce'] : null,
            deliveryKeyVersion: $row['delivery_key_version'] !== null ? (int) $row['delivery_key_version'] : null,
            deliveryStatus: (string) $row['delivery_status'],
            deliveredAt: $row['delivered_at'] !== null
                ? new DateTimeImmutable((string) $row['delivered_at'], $utc)
                : null,
            providerMessageId: $row['provider_message_id'] !== null ? (string) $row['provider_message_id'] : null,
            terminalAt: $row['terminal_at'] !== null
                ? new DateTimeImmutable((string) $row['terminal_at'], $utc)
                : null,
            deliveryLastError: $row['delivery_last_error'] !== null ? (string) $row['delivery_last_error'] : null,
            deliveryClearedAt: $row['delivery_cleared_at'] !== null
                ? new DateTimeImmutable((string) $row['delivery_cleared_at'], $utc)
                : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            lastSentAt: $row['last_sent_at'] !== null
                ? new DateTimeImmutable((string) $row['last_sent_at'], $utc)
                : null,
        );
    }
}
