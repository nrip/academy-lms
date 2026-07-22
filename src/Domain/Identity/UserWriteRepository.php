<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

interface UserWriteRepository
{
    /**
     * Inserts a pending_verification user with verified_at NULL and auth_version = 1.
     */
    public function insertPendingUser(
        string $normalizedEmail,
        string $normalizedMobileE164,
        string $passwordHash,
        string $termsVersion,
        string $privacyVersion,
        \DateTimeImmutable $now,
        string $timezone = 'Asia/Kolkata',
    ): int;

    /**
     * @return array{
     *   user_id: int,
     *   account_status: string,
     *   email: string,
     *   mobile_e164: string,
     *   email_verified_at: ?string,
     *   mobile_verified_at: ?string
     * }|null
     */
    public function findById(int $userId): ?array;

    /**
     * @return array{
     *   user_id: int,
     *   account_status: string,
     *   email_verified_at: ?string,
     *   mobile_verified_at: ?string,
     *   email: string,
     *   mobile_e164: string,
     *   auth_version: int|string
     * }|null
     */
    public function findByIdForUpdate(int $userId): ?array;

    /**
     * @return array{user_id: int, account_status: string}|null
     */
    public function findByEmail(string $normalizedEmail): ?array;

    /**
     * @return array{user_id: int, account_status: string}|null
     */
    public function findByMobileE164(string $normalizedMobileE164): ?array;

    /**
     * Lock user row for login evaluation.
     *
     * @return array{
     *   user_id: int,
     *   email: string,
     *   password_hash: string,
     *   account_status: string,
     *   email_verified_at: ?string,
     *   failed_login_count: int,
     *   failed_login_window_started_at: ?string,
     *   locked_until: ?string,
     *   auth_version: int|string
     * }|null
     */
    public function findCredentialsByEmailForUpdate(string $normalizedEmail): ?array;

    /**
     * @param array{
     *   failed_login_count: int,
     *   failed_login_window_started_at: ?\DateTimeImmutable,
     *   locked_until: ?\DateTimeImmutable,
     *   last_failed_login_at: \DateTimeImmutable
     * } $state
     */
    public function applyFailedLogin(int $userId, array $state): void;

    /**
     * @param array{
     *   failed_login_count: int,
     *   failed_login_window_started_at: null,
     *   locked_until: null,
     *   last_failed_login_at: null,
     *   last_login_at: \DateTimeImmutable
     * } $state
     */
    public function applySuccessfulLogin(int $userId, array $state, ?string $rehashedPassword): void;

    /**
     * Updates password hash, stamps password_changed_at, clears lockout counters,
     * and increments auth_version (guarded by application ceiling).
     *
     * @return array{auth_version_before: int, auth_version_after: int}
     */
    public function applyPasswordReset(int $userId, string $passwordHash, \DateTimeImmutable $now): array;

    /**
     * Conditionally stamps email_verified_at and may activate pending_verification → active.
     * Never activates suspended. Idempotent when email already verified.
     *
     * @return array{email_was_null: bool, activated: bool, account_status: string}
     */
    public function applyEmailVerification(int $userId, \DateTimeImmutable $now): array;

    /**
     * Sets mobile_verified_at when null. Never changes account_status.
     *
     * @return bool true when mobile_verified_at was previously null and is now set
     */
    public function applyMobileVerification(int $userId, \DateTimeImmutable $now): bool;
}
