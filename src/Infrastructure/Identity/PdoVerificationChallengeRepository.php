<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Identity\VerificationChallengeRecord;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Notifications\SealedSecret;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoVerificationChallengeRepository implements VerificationChallengeRepository
{
    private const SELECT_COLUMNS = 'verification_challenge_id, user_id, channel, destination_hmac, otp_binding_nonce,
                otp_hmac, expires_at, attempt_count, max_attempts, consumed_at, current_marker,
                otp_delivery_ciphertext, otp_delivery_nonce, otp_delivery_key_version, delivery_status,
                delivered_at, provider_message_id, terminal_at, delivery_last_error, otp_delivery_cleared_at,
                created_at, last_sent_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $verificationChallengeId): ?VerificationChallengeRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_challenges
             WHERE verification_challenge_id = :id
             LIMIT 1',
        );
        $stmt->execute(['id' => $verificationChallengeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findByIdForUpdate(int $verificationChallengeId): ?VerificationChallengeRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_challenges
             WHERE verification_challenge_id = :id
             LIMIT 1
             FOR UPDATE',
        );
        $stmt->execute(['id' => $verificationChallengeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function findCurrentByUserChannelForUpdate(int $userId, string $channel): ?VerificationChallengeRecord
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM verification_challenges
             WHERE user_id = :user_id
               AND channel = :channel
               AND current_marker = 1
             LIMIT 1
             FOR UPDATE',
        );
        $stmt->execute([
            'user_id' => $userId,
            'channel' => $channel,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function clearCurrentMarker(int $verificationChallengeId): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE verification_challenges
             SET current_marker = NULL
             WHERE verification_challenge_id = :id',
        );
        $stmt->execute(['id' => $verificationChallengeId]);
    }

    public function insertPendingCurrent(
        int $userId,
        string $channel,
        string $destinationHmac,
        string $otpBindingNonce,
        string $otpHmac,
        DateTimeImmutable $expiresAt,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO verification_challenges (
                user_id, channel, destination_hmac, otp_binding_nonce, otp_hmac, expires_at,
                attempt_count, max_attempts, consumed_at, current_marker,
                otp_delivery_ciphertext, otp_delivery_nonce, otp_delivery_key_version, delivery_status,
                delivered_at, provider_message_id, terminal_at, delivery_last_error, otp_delivery_cleared_at,
                created_at, last_sent_at
            ) VALUES (
                :user_id, :channel, :destination_hmac, :otp_binding_nonce, :otp_hmac, :expires_at,
                0, :max_attempts, NULL, 1,
                NULL, NULL, NULL, :delivery_status,
                NULL, NULL, NULL, NULL, NULL,
                :created_at, NULL
            )',
        );
        $stmt->execute([
            'user_id' => $userId,
            'channel' => $channel,
            'destination_hmac' => $destinationHmac,
            'otp_binding_nonce' => $otpBindingNonce,
            'otp_hmac' => $otpHmac,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            'max_attempts' => $maxAttempts,
            'delivery_status' => 'pending',
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateSealedDelivery(int $verificationChallengeId, SealedSecret $sealed, DateTimeImmutable $now): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE verification_challenges SET
                otp_delivery_ciphertext = :ciphertext,
                otp_delivery_nonce = :nonce,
                otp_delivery_key_version = :key_version,
                last_sent_at = :last_sent_at
             WHERE verification_challenge_id = :id',
        );
        $stmt->execute([
            'ciphertext' => $sealed->ciphertext,
            'nonce' => $sealed->nonce,
            'key_version' => $sealed->keyVersion,
            'last_sent_at' => $now->format('Y-m-d H:i:s.u'),
            'id' => $verificationChallengeId,
        ]);
    }

    public function incrementAttempt(int $verificationChallengeId): int
    {
        $pdo = $this->connections->connection();
        $update = $pdo->prepare(
            'UPDATE verification_challenges
             SET attempt_count = attempt_count + 1
             WHERE verification_challenge_id = :id',
        );
        $update->execute(['id' => $verificationChallengeId]);

        $fetch = $pdo->prepare(
            'SELECT attempt_count
             FROM verification_challenges
             WHERE verification_challenge_id = :id
             LIMIT 1',
        );
        $fetch->execute(['id' => $verificationChallengeId]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Verification challenge not found after attempt increment.');
        }

        return (int) $row['attempt_count'];
    }

    public function conditionalConsumeById(int $verificationChallengeId, DateTimeImmutable $now): bool
    {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE verification_challenges SET
                consumed_at = :consumed_at,
                current_marker = NULL,
                otp_delivery_ciphertext = NULL,
                otp_delivery_nonce = NULL,
                otp_delivery_key_version = NULL,
                otp_delivery_cleared_at = CASE
                    WHEN otp_delivery_ciphertext IS NOT NULL THEN :cleared_at
                    ELSE otp_delivery_cleared_at
                END
             WHERE verification_challenge_id = :id
               AND current_marker = 1
               AND consumed_at IS NULL
               AND expires_at > :now',
        );
        $stmt->execute([
            'consumed_at' => $ts,
            'cleared_at' => $ts,
            'id' => $verificationChallengeId,
            'now' => $ts,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markDelivered(
        int $verificationChallengeId,
        ?string $providerMessageId,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE verification_challenges SET
                delivery_status = :delivered_status,
                delivered_at = :delivered_at,
                provider_message_id = :provider_message_id,
                otp_delivery_ciphertext = NULL,
                otp_delivery_nonce = NULL,
                otp_delivery_key_version = NULL,
                otp_delivery_cleared_at = :cleared_at
             WHERE verification_challenge_id = :id
               AND delivery_status = :pending_status',
        );
        $stmt->execute([
            'delivered_status' => 'delivered',
            'delivered_at' => $ts,
            'provider_message_id' => $providerMessageId,
            'cleared_at' => $ts,
            'id' => $verificationChallengeId,
            'pending_status' => 'pending',
        ]);

        return $stmt->rowCount() === 1;
    }

    public function markTerminal(
        int $verificationChallengeId,
        string $redactedError,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $ts = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE verification_challenges SET
                delivery_status = :terminal_status,
                terminal_at = :terminal_at,
                delivery_last_error = :delivery_last_error,
                otp_delivery_ciphertext = NULL,
                otp_delivery_nonce = NULL,
                otp_delivery_key_version = NULL,
                otp_delivery_cleared_at = :cleared_at
             WHERE verification_challenge_id = :id
               AND delivery_status = :pending_status',
        );
        $stmt->execute([
            'terminal_status' => 'terminal',
            'terminal_at' => $ts,
            'delivery_last_error' => mb_substr($redactedError, 0, 512),
            'cleared_at' => $ts,
            'id' => $verificationChallengeId,
            'pending_status' => 'pending',
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): VerificationChallengeRecord
    {
        $utc = new DateTimeZone('UTC');

        return new VerificationChallengeRecord(
            verificationChallengeId: (int) $row['verification_challenge_id'],
            userId: (int) $row['user_id'],
            channel: (string) $row['channel'],
            destinationHmac: (string) $row['destination_hmac'],
            otpBindingNonce: (string) $row['otp_binding_nonce'],
            otpHmac: (string) $row['otp_hmac'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at'], $utc),
            attemptCount: (int) $row['attempt_count'],
            maxAttempts: (int) $row['max_attempts'],
            consumedAt: $row['consumed_at'] !== null
                ? new DateTimeImmutable((string) $row['consumed_at'], $utc)
                : null,
            currentMarker: $row['current_marker'] !== null ? (int) $row['current_marker'] : null,
            otpDeliveryCiphertext: $row['otp_delivery_ciphertext'] !== null
                ? (string) $row['otp_delivery_ciphertext']
                : null,
            otpDeliveryNonce: $row['otp_delivery_nonce'] !== null ? (string) $row['otp_delivery_nonce'] : null,
            otpDeliveryKeyVersion: $row['otp_delivery_key_version'] !== null
                ? (int) $row['otp_delivery_key_version']
                : null,
            deliveryStatus: (string) $row['delivery_status'],
            deliveredAt: $row['delivered_at'] !== null
                ? new DateTimeImmutable((string) $row['delivered_at'], $utc)
                : null,
            providerMessageId: $row['provider_message_id'] !== null ? (string) $row['provider_message_id'] : null,
            terminalAt: $row['terminal_at'] !== null
                ? new DateTimeImmutable((string) $row['terminal_at'], $utc)
                : null,
            deliveryLastError: $row['delivery_last_error'] !== null ? (string) $row['delivery_last_error'] : null,
            otpDeliveryClearedAt: $row['otp_delivery_cleared_at'] !== null
                ? new DateTimeImmutable((string) $row['otp_delivery_cleared_at'], $utc)
                : null,
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            lastSentAt: $row['last_sent_at'] !== null
                ? new DateTimeImmutable((string) $row['last_sent_at'], $utc)
                : null,
        );
    }
}
