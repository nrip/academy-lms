<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

final class DatabaseTestCase
{
    public static function available(): bool
    {
        try {
            self::pdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function pdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        $probe = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $probe->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        );
    }

    public static function connectionFactory(): ConnectionFactory
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        self::pdo();

        return new ConnectionFactory([
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        ]);
    }

    public static function migrate(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        self::syncDbEnvForPhinx();
        // NB: use a variable name distinct from phinx.php's own internal `$root` — `require`
        // shares the calling scope, so a same-named local here would silently get clobbered
        // by phinx.php's `$root = dirname(__DIR__);` line, corrupting the migrations path below.
        $repoRoot = dirname(__DIR__, 2);
        $configArray = require $repoRoot . '/phinx.php';
        $configArray['paths']['migrations'] = $repoRoot . '/database/migrations';
        $configArray['paths']['seeds'] = $repoRoot . '/database/seeds';
        $config = new Config($configArray);
        $manager = new Manager($config, new StringInput(''), new NullOutput());
        $manager->migrate('testing');
    }

    /**
     * Runs a single named Phinx seeder against the `testing` environment.
     * Respects whatever APP_ENV is currently set (callers wanting to exercise
     * a seeder's production/staging guard should set APP_ENV before calling).
     */
    public static function runSeeder(string $seederName): void
    {
        self::syncDbEnvForPhinx();
        $repoRoot = dirname(__DIR__, 2);
        $configArray = require $repoRoot . '/phinx.php';
        $configArray['paths']['migrations'] = $repoRoot . '/database/migrations';
        $configArray['paths']['seeds'] = $repoRoot . '/database/seeds';
        $config = new Config($configArray);
        $manager = new Manager($config, new StringInput(''), new NullOutput());
        $manager->seed('testing', $seederName);
    }

    public static function truncateWp01aTables(): void
    {
        self::truncateAllTestTables();
    }

    public static function truncateAllTestTables(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'document_upload_authorizations',
            'document_submissions',
            'applications',
            'batches',
        ] as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        // Unlock every CourseVersion before deleting its children/itself: the
        // immutability triggers block DELETE on eligibility_rules,
        // course_document_requirements and course_versions rows whose parent
        // version has locked_at IS NOT NULL. Clearing locked_at is itself
        // allowed by the update trigger (it does not guard that column).
        $pdo->exec('UPDATE `course_versions` SET `locked_at` = NULL');
        foreach ([
            'course_document_requirements',
            'eligibility_rules',
        ] as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        // Release the courses -> course_versions FK before truncating course_versions.
        $pdo->exec('UPDATE `courses` SET `current_published_version_id` = NULL');
        foreach ([
            'course_versions',
            'courses',
            'password_reset_authorizations',
            'token_confirmation_contexts',
            'verification_challenges',
            'verification_tokens',
            'sessions',
            'rate_limit_buckets',
            'outbox_messages',
            'scheduler_locks',
            'user_roles',
            'learner_qualifications',
            'learner_profiles',
            'users',
        ] as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        $pdo->exec('DROP TRIGGER IF EXISTS trg_audit_log_forbid_delete');
        $pdo->exec('DELETE FROM audit_log');
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_audit_log_forbid_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'
SQL);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param list<string> $roleKeys
     * @return array{user_id: int, auth_version: int}
     */
    public static function createSyntheticUser(
        string $email,
        string $mobile,
        array $roleKeys = [],
        string $accountStatus = AccountStatus::ACTIVE,
        ?\DateTimeImmutable $lockedUntil = null,
        int $authVersion = 1,
    ): array {
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('synthetic-local-password', PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 0, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?
            )',
        );
        $stmt->execute([
            strtolower($email),
            $now,
            $mobile,
            $now,
            $hash,
            $accountStatus,
            $lockedUntil?->format('Y-m-d H:i:s.u'),
            $authVersion,
            $now,
            $now,
            'synthetic.local.terms.v0',
            $now,
            'synthetic.local.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);
        $userId = (int) $pdo->lastInsertId();

        foreach ($roleKeys as $roleKey) {
            $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = :key');
            $roleStmt->execute(['key' => $roleKey]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if ($role === false) {
                throw new \RuntimeException('Missing seeded role: ' . $roleKey);
            }
            $assign = $pdo->prepare(
                'INSERT INTO user_roles (
                    user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
                ) VALUES (?, ?, NULL, ?, 1, ?, ?)',
            );
            $assign->execute([
                $userId,
                (int) $role['role_id'],
                $now,
                $now,
                $now,
            ]);
        }

        return ['user_id' => $userId, 'auth_version' => $authVersion];
    }

    /**
     * Ensures a learner_profiles stub row exists for the user and returns its id.
     */
    public static function ensureLearnerProfileStub(int $userId): int
    {
        $pdo = self::pdo();
        $existing = $pdo->prepare('SELECT learner_profile_id FROM learner_profiles WHERE user_id = :user_id');
        $existing->execute(['user_id' => $userId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (int) $row['learner_profile_id'];
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $insert = $pdo->prepare(
            'INSERT INTO learner_profiles (user_id, row_version, created_at, updated_at)
             VALUES (:user_id, 1, :created_at, :updated_at)',
        );
        $insert->execute([
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function roleId(string $roleKey): int
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = :key');
        $stmt->execute(['key' => $roleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Role not found: ' . $roleKey);
        }

        return (int) $row['role_id'];
    }

    public static function authVersion(int $userId): int
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare('SELECT auth_version FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('User not found');
        }

        return AuthVersion::fromDatabase($row['auth_version']);
    }

    /**
     * @return array{session: string, csrf: string, record_id: int}
     */
    public static function bindSessionForUser(
        int $userId,
        int $authVersion,
        string $authStage = AuthStage::FULLY_AUTHENTICATED,
    ): array {
        $container = ApplicationFactory::container('testing');
        /** @var \Academy\Application\Security\SessionService $sessions */
        $sessions = $container->get(\Academy\Application\Security\SessionService::class);
        $loaded = $sessions->loadOrCreate(null, '127.0.0.1', 'phpunit');
        $bound = $sessions->bindUser($loaded['record'], $userId, $authVersion, [
            'auth_stage' => $authStage,
        ]);

        return [
            'session' => $loaded['raw_token'],
            'csrf' => $loaded['raw_csrf'],
            'record_id' => $bound->sessionId,
        ];
    }

    /**
     * A session with no bound user — CSRF passes (a real session exists),
     * but AuthContext::authenticated is false, so route-level permission
     * gates must still reject with 401, distinguishing "no CSRF" (403) from
     * "no auth" (401).
     *
     * @return array{session: string, csrf: string}
     */
    public static function anonymousSessionFixture(): array
    {
        $container = ApplicationFactory::container('testing');
        /** @var \Academy\Application\Security\SessionService $sessions */
        $sessions = $container->get(\Academy\Application\Security\SessionService::class);
        $loaded = $sessions->loadOrCreate(null, '127.0.0.1', 'phpunit');

        return ['session' => $loaded['raw_token'], 'csrf' => $loaded['raw_csrf']];
    }

    public static function applicantFixture(): array
    {
        return self::createSyntheticUser(
            'applicant.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
        );
    }

    public static function financeFixture(): array
    {
        return self::createSyntheticUser(
            'finance.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::FINANCE_ADMIN],
        );
    }

    public static function reviewerFixture(): array
    {
        return self::createSyntheticUser(
            'reviewer.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::CREDENTIAL_REVIEWER],
        );
    }

    public static function superAdminFixture(): array
    {
        return self::createSyntheticUser(
            'super.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::SUPER_ADMIN],
        );
    }

    /**
     * Inserts a fully published + locked Course/CourseVersion pair for tests
     * (mirrors Wp02DemoCatalogueSeeder's seed order without its idempotency
     * bookkeeping). Returns raw ids; callers add batches via seedBatch().
     *
     * @param array<string, mixed> $overrides
     * @return array{course_id: int, version_id: int}
     */
    public static function seedPublishedCourse(array $overrides = []): array
    {
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $suffix = bin2hex(random_bytes(4));

        $courseCode = $overrides['course_code'] ?? ('TEST-COURSE-' . $suffix);
        $slug = $overrides['slug'] ?? ('test-course-' . $suffix);
        $masterTitle = $overrides['master_title'] ?? 'Test Course ' . $suffix;
        $courseStatus = $overrides['course_status'] ?? \Academy\Domain\Courses\CourseStatus::ACTIVE;

        $insertCourse = $pdo->prepare(
            'INSERT INTO courses (course_code, slug, master_title, status, current_published_version_id, created_at, updated_at)
             VALUES (:course_code, :slug, :master_title, :status, NULL, :created_at, :updated_at)',
        );
        $insertCourse->execute([
            'course_code' => $courseCode,
            'slug' => $slug,
            'master_title' => $masterTitle,
            'status' => $courseStatus,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $courseId = (int) $pdo->lastInsertId();

        $insertVersion = $pdo->prepare(
            'INSERT INTO course_versions (
                course_id, version_number, title, description, learning_objectives, intended_audience,
                syllabus_summary, admission_mode, delivery_type, duration_text, validity_period_days,
                standard_fee, gst_rate, currency, certificate_type, faq_json, status,
                published_at, locked_at, locked_reason, created_at, updated_at
            ) VALUES (
                :course_id, 1, :title, :description, :learning_objectives, :intended_audience,
                :syllabus_summary, :admission_mode, :delivery_type, :duration_text, :validity_period_days,
                :standard_fee, :gst_rate, :currency, :certificate_type, NULL, :status,
                NULL, NULL, NULL, :created_at, :updated_at
            )',
        );
        $insertVersion->execute([
            'course_id' => $courseId,
            'title' => $overrides['title'] ?? ('Test Course ' . $suffix . ' — Version 1'),
            'description' => $overrides['description'] ?? 'Synthetic test course description.',
            'learning_objectives' => $overrides['learning_objectives'] ?? 'Synthetic learning objectives.',
            'intended_audience' => $overrides['intended_audience'] ?? 'Doctors and nurses.',
            'syllabus_summary' => $overrides['syllabus_summary'] ?? 'Synthetic syllabus summary.',
            'admission_mode' => $overrides['admission_mode'] ?? 'A',
            'delivery_type' => $overrides['delivery_type'] ?? 'online',
            'duration_text' => $overrides['duration_text'] ?? '4 weeks',
            'validity_period_days' => $overrides['validity_period_days'] ?? 365,
            'standard_fee' => $overrides['standard_fee'] ?? '10000.00',
            'gst_rate' => $overrides['gst_rate'] ?? '18.00',
            'currency' => $overrides['currency'] ?? 'INR',
            'certificate_type' => $overrides['certificate_type'] ?? 'Certificate of Completion',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $versionId = (int) $pdo->lastInsertId();

        $versionStatus = $overrides['version_status'] ?? \Academy\Domain\Courses\CourseVersionStatus::PUBLISHED;
        $locked = $overrides['locked'] ?? true;

        $updateVersion = $pdo->prepare(
            'UPDATE course_versions
             SET status = :status, published_at = :published_at, locked_at = :locked_at, locked_reason = :locked_reason,
                 updated_at = :updated_at
             WHERE version_id = :id',
        );
        $updateVersion->execute([
            'status' => $versionStatus,
            'published_at' => $versionStatus === \Academy\Domain\Courses\CourseVersionStatus::PUBLISHED ? $now : null,
            'locked_at' => $locked ? $now : null,
            'locked_reason' => $locked ? 'published' : null,
            'updated_at' => $now,
            'id' => $versionId,
        ]);

        if (($overrides['set_current_published_version'] ?? true) === true) {
            $pdo->prepare('UPDATE courses SET current_published_version_id = :version_id WHERE course_id = :course_id')
                ->execute(['version_id' => $versionId, 'course_id' => $courseId]);
        }

        return ['course_id' => $courseId, 'version_id' => $versionId];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function seedBatch(int $versionId, array $overrides = []): int
    {
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $suffix = bin2hex(random_bytes(4));

        $days = static fn (int $offset): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify($offset . ' days')
            ->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO batches (
                course_version_id, batch_code, name, starts_at, ends_at, applications_open_at,
                applications_close_at, min_capacity, max_capacity, delivery_mode, venue_or_online_details,
                timezone, fee_override, currency, status, access_expires_at, created_at, updated_at
            ) VALUES (
                :course_version_id, :batch_code, :name, :starts_at, :ends_at, :applications_open_at,
                :applications_close_at, :min_capacity, :max_capacity, :delivery_mode, :venue_or_online_details,
                :timezone, :fee_override, :currency, :status, :access_expires_at, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_version_id' => $versionId,
            'batch_code' => $overrides['batch_code'] ?? ('TEST-BATCH-' . $suffix),
            'name' => $overrides['name'] ?? ('Test batch ' . $suffix),
            'starts_at' => $overrides['starts_at'] ?? $days(30),
            'ends_at' => $overrides['ends_at'] ?? $days(60),
            'applications_open_at' => $overrides['applications_open_at'] ?? $days(-5),
            'applications_close_at' => $overrides['applications_close_at'] ?? $days(20),
            'min_capacity' => $overrides['min_capacity'] ?? 5,
            'max_capacity' => $overrides['max_capacity'] ?? 30,
            'delivery_mode' => $overrides['delivery_mode'] ?? 'online',
            'venue_or_online_details' => $overrides['venue_or_online_details'] ?? 'Online sessions.',
            'timezone' => $overrides['timezone'] ?? 'Asia/Kolkata',
            'fee_override' => $overrides['fee_override'] ?? null,
            'currency' => $overrides['currency'] ?? 'INR',
            'status' => $overrides['status'] ?? \Academy\Domain\Courses\BatchStatus::OPEN_FOR_APPLICATIONS,
            'access_expires_at' => $overrides['access_expires_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Convenience wrapper: one published+locked course/version with one
     * currently-open batch. Returns ids for the common-case test setup.
     *
     * @param array<string, mixed> $courseOverrides
     * @param array<string, mixed> $batchOverrides
     * @return array{course_id: int, version_id: int, batch_id: int}
     */
    public static function seedPublishedCatalogue(array $courseOverrides = [], array $batchOverrides = []): array
    {
        $course = self::seedPublishedCourse($courseOverrides);
        $batchId = self::seedBatch($course['version_id'], $batchOverrides);

        return [
            'course_id' => $course['course_id'],
            'version_id' => $course['version_id'],
            'batch_id' => $batchId,
        ];
    }

    /**
     * Inserts a CourseDocumentRequirement row. Must be called while the
     * owning CourseVersion is still unlocked — the WP-02 immutability
     * trigger rejects INSERTs against a locked version. Callers that need a
     * published+locked catalogue with requirements should seed with
     * `locked => false`, add requirements, then call lockCourseVersion().
     *
     * @param array<string, mixed> $overrides
     */
    public static function seedDocumentRequirement(int $versionId, array $overrides = []): int
    {
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO course_document_requirements (
                course_version_id, document_name, description, mandatory_flag, accepted_file_types,
                max_size_bytes, single_or_multiple, reuse_allowed, reviewer_instructions, sort_order,
                created_at, updated_at
            ) VALUES (
                :course_version_id, :document_name, :description, :mandatory_flag, :accepted_file_types,
                :max_size_bytes, :single_or_multiple, :reuse_allowed, :reviewer_instructions, :sort_order,
                :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_version_id' => $versionId,
            'document_name' => $overrides['document_name'] ?? 'Medical council registration certificate',
            'description' => $overrides['description'] ?? 'Upload a clear scan of your registration certificate.',
            'mandatory_flag' => ($overrides['mandatory'] ?? true) ? 1 : 0,
            'accepted_file_types' => $overrides['accepted_file_types'] ?? 'pdf,jpg,jpeg,png',
            'max_size_bytes' => $overrides['max_size_bytes'] ?? 5242880,
            'single_or_multiple' => $overrides['single_or_multiple'] ?? 'single',
            'reuse_allowed' => ($overrides['reuse_allowed'] ?? false) ? 1 : 0,
            'reviewer_instructions' => $overrides['reviewer_instructions'] ?? null,
            'sort_order' => $overrides['sort_order'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function lockCourseVersion(int $versionId, string $reason = 'published'): void
    {
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo->prepare('UPDATE course_versions SET locked_at = :locked_at, locked_reason = :reason, updated_at = :updated_at WHERE version_id = :id')
            ->execute(['locked_at' => $now, 'reason' => $reason, 'updated_at' => $now, 'id' => $versionId]);
    }

    /**
     * Published catalogue (course + version + batch) with document
     * requirements attached before the version is locked.
     *
     * @param array<string, mixed> $courseOverrides
     * @param array<string, mixed> $batchOverrides
     * @param list<array<string, mixed>> $requirementOverridesList
     * @return array{course_id: int, version_id: int, batch_id: int, requirement_ids: list<int>}
     */
    public static function seedPublishedCatalogueWithRequirements(
        array $courseOverrides = [],
        array $batchOverrides = [],
        array $requirementOverridesList = [[]],
    ): array {
        $course = self::seedPublishedCourse($courseOverrides + ['locked' => false]);

        $requirementIds = [];
        foreach ($requirementOverridesList as $requirementOverrides) {
            $requirementIds[] = self::seedDocumentRequirement($course['version_id'], $requirementOverrides);
        }

        self::lockCourseVersion($course['version_id']);

        $batchId = self::seedBatch($course['version_id'], $batchOverrides);

        return [
            'course_id' => $course['course_id'],
            'version_id' => $course['version_id'],
            'batch_id' => $batchId,
            'requirement_ids' => $requirementIds,
        ];
    }

    private static function syncDbEnvForPhinx(): void
    {
        $map = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
        ];
        foreach ($map as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
