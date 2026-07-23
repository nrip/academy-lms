<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * WP-04 demo reviewer — local/testing/ci only. Idempotent by reviewer email and
 * batch scope. Creates reviewer-demo@example.test with credential_reviewer role
 * and batch scope on the WP-02 demo obesity open cohort.
 *
 * Known password for local/tests: reviewer-demo-password
 */
final class Wp04ReviewerDemoSeeder extends AbstractSeed
{
    private const REVIEWER_EMAIL = 'reviewer-demo@example.test';
    private const REVIEWER_MOBILE = '+919876543210';
    private const REVIEWER_PASSWORD = 'reviewer-demo-password';
    private const DEMO_BATCH_CODE = 'WP02-DEMO-OBESITY-101-OPEN';

    public function run(): void
    {
        $env = $this->currentEnv();
        if (in_array($env, ['production', 'staging'], true)) {
            throw new RuntimeException('Wp04ReviewerDemoSeeder must never run in ' . $env . '.');
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = $this->nowUtc();

        $reviewerUserId = $this->ensureReviewerUser($pdo, $now);
        $batchId = $this->findBatchId($pdo, self::DEMO_BATCH_CODE);
        if ($batchId === null) {
            throw new RuntimeException(
                'Demo batch ' . self::DEMO_BATCH_CODE . ' not found. Run Wp02DemoCatalogueSeeder first.',
            );
        }

        $this->ensureBatchScope($pdo, $reviewerUserId, $batchId, $now);
    }

    private function ensureReviewerUser(PDO $pdo, string $now): int
    {
        $existing = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => self::REVIEWER_EMAIL]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $userId = (int) $row['user_id'];
            $this->ensureReviewerRole($pdo, $userId, $now);

            return $userId;
        }

        $hash = password_hash(self::REVIEWER_PASSWORD, PASSWORD_ARGON2ID);
        $insert = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                :email, :email_verified_at, :mobile, :mobile_verified_at, :password_hash,
                :status, 0, NULL, 1,
                :password_changed_at, :terms_accepted_at, :terms_version,
                :privacy_accepted_at, :privacy_version, :timezone, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'email' => self::REVIEWER_EMAIL,
            'email_verified_at' => $now,
            'mobile' => self::REVIEWER_MOBILE,
            'mobile_verified_at' => $now,
            'password_hash' => $hash,
            'status' => 'active',
            'password_changed_at' => $now,
            'terms_accepted_at' => $now,
            'terms_version' => 'synthetic.local.terms.v0',
            'privacy_accepted_at' => $now,
            'privacy_version' => 'synthetic.local.privacy.v0',
            'timezone' => 'Asia/Kolkata',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $this->ensureReviewerRole($pdo, $userId, $now);

        return $userId;
    }

    private function ensureReviewerRole(PDO $pdo, int $userId, string $now): void
    {
        $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = :key');
        $roleStmt->execute(['key' => 'credential_reviewer']);
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            throw new RuntimeException('Missing credential_reviewer role.');
        }
        $roleId = (int) $role['role_id'];

        $existing = $pdo->prepare(
            'SELECT user_role_id FROM user_roles WHERE user_id = :user_id AND role_id = :role_id AND current_marker = 1',
        );
        $existing->execute(['user_id' => $userId, 'role_id' => $roleId]);
        if ($existing->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

        $assign = $pdo->prepare(
            'INSERT INTO user_roles (
                user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
            ) VALUES (?, ?, NULL, ?, 1, ?, ?)',
        );
        $assign->execute([$userId, $roleId, $now, $now, $now]);
    }

    private function ensureBatchScope(PDO $pdo, int $reviewerUserId, int $batchId, string $now): void
    {
        $existing = $pdo->prepare(
            'SELECT scope_assignment_id FROM reviewer_scope_assignments
             WHERE reviewer_user_id = :reviewer_user_id
               AND scope_type = :scope_type
               AND batch_id = :batch_id
               AND revoked_at IS NULL
             LIMIT 1',
        );
        $existing->execute([
            'reviewer_user_id' => $reviewerUserId,
            'scope_type' => 'batch',
            'batch_id' => $batchId,
        ]);
        if ($existing->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO reviewer_scope_assignments (
                reviewer_user_id, scope_type, course_id, course_version_id, batch_id,
                include_future_versions, effective_from, effective_to, revoked_at,
                revoked_reason, created_by_user_id, revoked_by_user_id, created_at, updated_at
            ) VALUES (
                :reviewer_user_id, :scope_type, NULL, NULL, :batch_id,
                0, :effective_from, NULL, NULL,
                NULL, :created_by_user_id, NULL, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'reviewer_user_id' => $reviewerUserId,
            'scope_type' => 'batch',
            'batch_id' => $batchId,
            'effective_from' => $now,
            'created_by_user_id' => $reviewerUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function findBatchId(PDO $pdo, string $batchCode): ?int
    {
        $stmt = $pdo->prepare('SELECT batch_id FROM batches WHERE batch_code = :batch_code LIMIT 1');
        $stmt->execute(['batch_code' => $batchCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['batch_id'];
    }

    private function currentEnv(): string
    {
        $value = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';

        return is_string($value) ? strtolower($value) : 'local';
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
