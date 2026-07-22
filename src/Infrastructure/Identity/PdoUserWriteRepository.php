<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Identity;

use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AccountActivationPolicy;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoUserWriteRepository implements UserWriteRepository
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function insertPendingUser(
        string $normalizedEmail,
        string $normalizedMobileE164,
        string $passwordHash,
        string $termsVersion,
        string $privacyVersion,
        DateTimeImmutable $now,
        string $timezone = 'Asia/Kolkata',
    ): int {
        $pdo = $this->connections->connection();
        $nowStr = $this->formatUtc($now);

        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, email_suppressed_at, timezone,
                created_at, updated_at
            ) VALUES (
                :email, NULL, :mobile_e164, NULL, :password_hash,
                :account_status, 0, NULL, 1,
                :password_changed_at, :terms_accepted_at, :terms_version,
                :privacy_accepted_at, :privacy_version, NULL, :timezone,
                :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'email' => $normalizedEmail,
            'mobile_e164' => $normalizedMobileE164,
            'password_hash' => $passwordHash,
            'account_status' => AccountStatus::PENDING_VERIFICATION,
            'password_changed_at' => $nowStr,
            'terms_accepted_at' => $nowStr,
            'terms_version' => $termsVersion,
            'privacy_accepted_at' => $nowStr,
            'privacy_version' => $privacyVersion,
            'timezone' => $timezone,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findById(int $userId): ?array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT user_id, account_status, email, mobile_e164, email_verified_at, mobile_verified_at
             FROM users
             WHERE user_id = :user_id
             LIMIT 1',
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUserRow($row);
    }

    public function findByIdForUpdate(int $userId): ?array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT user_id, account_status, email_verified_at, mobile_verified_at,
                    email, mobile_e164, auth_version
             FROM users
             WHERE user_id = :user_id
             FOR UPDATE',
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapLockedRow($row);
    }

    public function findByEmail(string $normalizedEmail): ?array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT user_id, account_status
             FROM users
             WHERE email = :email
             LIMIT 1',
        );
        $stmt->execute(['email' => $normalizedEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'account_status' => (string) $row['account_status'],
        ];
    }

    public function findByMobileE164(string $normalizedMobileE164): ?array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT user_id, account_status
             FROM users
             WHERE mobile_e164 = :mobile_e164
             LIMIT 1',
        );
        $stmt->execute(['mobile_e164' => $normalizedMobileE164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'account_status' => (string) $row['account_status'],
        ];
    }

    public function applyEmailVerification(int $userId, DateTimeImmutable $now): array
    {
        $row = $this->findByIdForUpdate($userId);
        if ($row === null) {
            throw new NotFoundException('User not found.');
        }

        $emailAlreadyVerified = $row['email_verified_at'] !== null;
        $decision = AccountActivationPolicy::applyEmailVerified(
            (string) $row['account_status'],
            $emailAlreadyVerified,
        );

        $nowStr = $this->formatUtc($now);
        $pdo = $this->connections->connection();
        $update = $pdo->prepare(
            'UPDATE users
             SET email_verified_at = CASE
                    WHEN email_verified_at IS NULL THEN :email_verified_at
                    ELSE email_verified_at
                 END,
                 account_status = :account_status,
                 updated_at = :updated_at
             WHERE user_id = :user_id',
        );
        $update->execute([
            'email_verified_at' => $nowStr,
            'account_status' => $decision['new_status'],
            'updated_at' => $nowStr,
            'user_id' => $userId,
        ]);

        return [
            'email_was_null' => !$emailAlreadyVerified,
            'activated' => $decision['activate'],
            'account_status' => $decision['new_status'],
        ];
    }

    public function applyMobileVerification(int $userId, DateTimeImmutable $now): bool
    {
        $row = $this->findByIdForUpdate($userId);
        if ($row === null) {
            throw new NotFoundException('User not found.');
        }

        if ($row['mobile_verified_at'] !== null) {
            return false;
        }

        $pdo = $this->connections->connection();
        $nowStr = $this->formatUtc($now);
        $stmt = $pdo->prepare(
            'UPDATE users
             SET mobile_verified_at = :mobile_verified_at,
                 updated_at = :updated_at
             WHERE user_id = :user_id
               AND mobile_verified_at IS NULL',
        );
        $stmt->execute([
            'mobile_verified_at' => $nowStr,
            'updated_at' => $nowStr,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   user_id: int,
     *   account_status: string,
     *   email: string,
     *   mobile_e164: string,
     *   email_verified_at: ?string,
     *   mobile_verified_at: ?string
     * }
     */
    private function mapUserRow(array $row): array
    {
        return [
            'user_id' => (int) $row['user_id'],
            'account_status' => (string) $row['account_status'],
            'email' => (string) $row['email'],
            'mobile_e164' => (string) $row['mobile_e164'],
            'email_verified_at' => $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null,
            'mobile_verified_at' => $row['mobile_verified_at'] !== null ? (string) $row['mobile_verified_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   user_id: int,
     *   account_status: string,
     *   email_verified_at: ?string,
     *   mobile_verified_at: ?string,
     *   email: string,
     *   mobile_e164: string,
     *   auth_version: int|string
     * }
     */
    private function mapLockedRow(array $row): array
    {
        return [
            ...$this->mapUserRow($row),
            'auth_version' => $row['auth_version'],
        ];
    }

    private function formatUtc(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
