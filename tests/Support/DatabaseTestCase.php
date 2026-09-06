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
        $pdo->exec('DROP TRIGGER IF EXISTS trg_verification_audit_log_forbid_delete');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_verification_audit_log_forbid_update');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_payment_status_history_forbid_delete');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_payment_status_history_forbid_update');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_enrolment_status_history_forbid_delete');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_enrolment_status_history_forbid_update');
        foreach ([
            'verification_audit_log',
            'application_review_assignments',
            'reviewer_scope_assignments',
            'document_upload_authorizations',
            'document_submissions',
            'assessment_attempt_status_history',
            'assessment_responses',
            'assessment_attempt_questions',
            'assessment_attempts',
            'certificate_events',
            'certificates',
            'content_progress',
            'enrolment_status_history',
            'enrolments',
            'notification_deliveries',
            'payment_status_history',
            'payment_webhook_events',
            'payments',
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
            'assessment_question_links',
            'assessments',
            'question_options',
            'questions',
            'question_banks',
            'content_items',
            'modules',
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
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_verification_audit_log_forbid_update
BEFORE UPDATE ON verification_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verification_audit_log is append-only'
SQL);
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_verification_audit_log_forbid_delete
BEFORE DELETE ON verification_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verification_audit_log is append-only'
SQL);
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_payment_status_history_forbid_update
BEFORE UPDATE ON payment_status_history
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_status_history is append-only'
SQL);
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_payment_status_history_forbid_delete
BEFORE DELETE ON payment_status_history
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_status_history is append-only'
SQL);
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_enrolment_status_history_forbid_update
BEFORE UPDATE ON enrolment_status_history
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'enrolment_status_history is append-only'
SQL);
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_enrolment_status_history_forbid_delete
BEFORE DELETE ON enrolment_status_history
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'enrolment_status_history is append-only'
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

    /**
     * Sets a usable certificate/full name on the learner profile (creates stub if needed).
     */
    public static function setLearnerCertificateName(
        int $userId,
        ?string $certificateName = 'Dr Synthetic Learner',
        ?string $firstName = 'Synthetic',
        ?string $lastName = 'Learner',
    ): void {
        $profileId = self::ensureLearnerProfileStub($userId);
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'UPDATE learner_profiles SET
                certificate_name = :certificate_name,
                certificate_name_confirmed = :confirmed,
                first_name = :first_name,
                last_name = :last_name,
                updated_at = :updated_at
             WHERE learner_profile_id = :id',
        )->execute([
            'certificate_name' => $certificateName,
            'confirmed' => $certificateName !== null && $certificateName !== '' ? 1 : 0,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'updated_at' => $now,
            'id' => $profileId,
        ]);
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

    public static function courseAdminFixture(): array
    {
        return self::createSyntheticUser(
            'courseadmin.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::COURSE_ADMIN],
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
     * Published + locked CourseVersion with one immediate module and text lessons.
     *
     * @param list<string> $lessonTitles
     * @param array<string, mixed> $courseOverrides
     * @return array{course_id: int, version_id: int, module_id: int, content_ids: list<int>}
     */
    public static function seedPublishedCourseWithCurriculum(
        array $lessonTitles = ['Lesson 1', 'Lesson 2'],
        array $courseOverrides = [],
    ): array {
        $ids = self::seedPublishedCourse(array_merge($courseOverrides, [
            'locked' => false,
            'version_status' => \Academy\Domain\Courses\CourseVersionStatus::DRAFT,
            'set_current_published_version' => false,
        ]));
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $moduleStmt = $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag, release_rule,
                prerequisite_module_id, created_at, updated_at
             ) VALUES (
                :version_id, 1, :title, :description, 1, :release_rule, NULL, :created_at, :updated_at
             )',
        );
        $moduleStmt->execute([
            'version_id' => $ids['version_id'],
            'title' => 'Module 1',
            'description' => 'Foundations',
            'release_rule' => \Academy\Domain\Courses\ModuleReleaseRule::IMMEDIATE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $moduleId = (int) $pdo->lastInsertId();

        $contentIds = [];
        $contentStmt = $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, object_key,
                mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, :sequence, :content_type, :title, :body_text, NULL,
                1, :completion_rule, :created_at, :updated_at
             )',
        );
        foreach (array_values($lessonTitles) as $index => $title) {
            $contentStmt->execute([
                'module_id' => $moduleId,
                'sequence' => $index + 1,
                'content_type' => \Academy\Domain\Courses\ContentItemType::TEXT_LESSON,
                'title' => $title,
                'body_text' => 'Body for ' . $title,
                'completion_rule' => \Academy\Domain\Courses\ContentCompletionRule::MARK_COMPLETE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $contentIds[] = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            'UPDATE course_versions
             SET status = :status, published_at = :published_at, locked_at = :locked_at,
                 locked_reason = :locked_reason, updated_at = :updated_at
             WHERE version_id = :id',
        )->execute([
            'status' => \Academy\Domain\Courses\CourseVersionStatus::PUBLISHED,
            'published_at' => $now,
            'locked_at' => $now,
            'locked_reason' => 'published',
            'updated_at' => $now,
            'id' => $ids['version_id'],
        ]);
        $pdo->prepare('UPDATE courses SET current_published_version_id = :version_id WHERE course_id = :course_id')
            ->execute(['version_id' => $ids['version_id'], 'course_id' => $ids['course_id']]);

        return [
            'course_id' => $ids['course_id'],
            'version_id' => $ids['version_id'],
            'module_id' => $moduleId,
            'content_ids' => $contentIds,
        ];
    }

    /**
     * Published CourseVersion with one MCQ assessment (snapshot-ready linked questions).
     *
     * @param array{
     *   question_count?: int,
     *   questions_per_attempt?: int,
     *   pass_threshold_percent?: string,
     *   max_attempts?: int,
     *   cooldown_seconds?: ?int
     * } $overrides
     * @return array{
     *   course_id: int,
     *   version_id: int,
     *   module_id: int,
     *   content_id: int,
     *   assessment_id: int,
     *   bank_id: int,
     *   question_ids: list<int>,
     *   correct_option_ids_by_question: array<int, int>
     * }
     */
    public static function seedPublishedCourseWithMcqAssessment(array $overrides = []): array
    {
        $questionCount = (int) ($overrides['question_count'] ?? 2);
        $questionsPerAttempt = (int) ($overrides['questions_per_attempt'] ?? $questionCount);
        $passThreshold = (string) ($overrides['pass_threshold_percent'] ?? '50.00');
        $maxAttempts = (int) ($overrides['max_attempts'] ?? 3);
        $cooldown = array_key_exists('cooldown_seconds', $overrides)
            ? $overrides['cooldown_seconds']
            : null;

        $ids = self::seedPublishedCourse([
            'locked' => false,
            'version_status' => \Academy\Domain\Courses\CourseVersionStatus::DRAFT,
            'set_current_published_version' => false,
        ]);
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag, release_rule,
                prerequisite_module_id, created_at, updated_at
             ) VALUES (
                :version_id, 1, :title, :description, 1, :release_rule, NULL, :created_at, :updated_at
             )',
        )->execute([
            'version_id' => $ids['version_id'],
            'title' => 'Assessment module',
            'description' => 'MCQ runtime fixture',
            'release_rule' => \Academy\Domain\Courses\ModuleReleaseRule::IMMEDIATE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $moduleId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, object_key,
                mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, 1, :content_type, :title, NULL, NULL,
                1, :completion_rule, :created_at, :updated_at
             )',
        )->execute([
            'module_id' => $moduleId,
            'content_type' => \Academy\Domain\Courses\ContentItemType::MCQ_ASSESSMENT,
            'title' => 'Module assessment',
            'completion_rule' => \Academy\Domain\Courses\ContentCompletionRule::ASSESSMENT_PASSED,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contentId = (int) $pdo->lastInsertId();

        $existingBank = $pdo->prepare('SELECT bank_id FROM question_banks WHERE course_id = :course_id');
        $existingBank->execute(['course_id' => $ids['course_id']]);
        $bankRow = $existingBank->fetch(PDO::FETCH_ASSOC);
        if ($bankRow === false) {
            $pdo->prepare(
                'INSERT INTO question_banks (course_id, title, created_at, updated_at)
                 VALUES (:course_id, :title, :created_at, :updated_at)',
            )->execute([
                'course_id' => $ids['course_id'],
                'title' => 'Course question bank',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $bankId = (int) $pdo->lastInsertId();
        } else {
            $bankId = (int) $bankRow['bank_id'];
        }

        $questionIds = [];
        $correctByQuestion = [];
        for ($i = 1; $i <= $questionCount; ++$i) {
            $pdo->prepare(
                'INSERT INTO questions (
                    bank_id, question_type, stem, marks, status, version, created_at, updated_at
                 ) VALUES (
                    :bank_id, :question_type, :stem, :marks, :status, 1, :created_at, :updated_at
                 )',
            )->execute([
                'bank_id' => $bankId,
                'question_type' => 'mcq_single',
                'stem' => 'Fixture question ' . $i . '?',
                'marks' => '1.00',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $questionId = (int) $pdo->lastInsertId();
            $questionIds[] = $questionId;

            $pdo->prepare(
                'INSERT INTO question_options (
                    question_id, sequence, option_text, is_correct, created_at, updated_at
                 ) VALUES
                    (:qid, 1, :correct_text, 1, :created_at, :updated_at),
                    (:qid2, 2, :wrong_text, 0, :created_at2, :updated_at2)',
            )->execute([
                'qid' => $questionId,
                'correct_text' => 'Correct ' . $i,
                'created_at' => $now,
                'updated_at' => $now,
                'qid2' => $questionId,
                'wrong_text' => 'Wrong ' . $i,
                'created_at2' => $now,
                'updated_at2' => $now,
            ]);
            $correctOptionId = (int) $pdo->query(
                'SELECT option_id FROM question_options WHERE question_id = ' . $questionId
                . ' AND is_correct = 1 LIMIT 1',
            )->fetchColumn();
            $correctByQuestion[$questionId] = $correctOptionId;
        }

        $pdo->prepare(
            'INSERT INTO assessments (
                content_id, title, questions_per_attempt, pass_threshold_percent, time_limit_seconds,
                max_attempts, cooldown_seconds, randomise_questions, randomise_options,
                created_at, updated_at
             ) VALUES (
                :content_id, :title, :questions_per_attempt, :pass_threshold_percent, NULL,
                :max_attempts, :cooldown_seconds, 0, 0, :created_at, :updated_at
             )',
        )->execute([
            'content_id' => $contentId,
            'title' => 'Demo MCQ',
            'questions_per_attempt' => $questionsPerAttempt,
            'pass_threshold_percent' => $passThreshold,
            'max_attempts' => $maxAttempts,
            'cooldown_seconds' => $cooldown,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assessmentId = (int) $pdo->lastInsertId();

        $linkStmt = $pdo->prepare(
            'INSERT INTO assessment_question_links (
                assessment_id, question_id, sequence, created_at, updated_at
             ) VALUES (
                :assessment_id, :question_id, :sequence, :created_at, :updated_at
             )',
        );
        foreach ($questionIds as $index => $questionId) {
            $linkStmt->execute([
                'assessment_id' => $assessmentId,
                'question_id' => $questionId,
                'sequence' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pdo->prepare(
            'UPDATE course_versions
             SET status = :status, published_at = :published_at, locked_at = :locked_at,
                 locked_reason = :locked_reason, updated_at = :updated_at
             WHERE version_id = :id',
        )->execute([
            'status' => \Academy\Domain\Courses\CourseVersionStatus::PUBLISHED,
            'published_at' => $now,
            'locked_at' => $now,
            'locked_reason' => 'published',
            'updated_at' => $now,
            'id' => $ids['version_id'],
        ]);
        $pdo->prepare('UPDATE courses SET current_published_version_id = :version_id WHERE course_id = :course_id')
            ->execute(['version_id' => $ids['version_id'], 'course_id' => $ids['course_id']]);

        return [
            'course_id' => $ids['course_id'],
            'version_id' => $ids['version_id'],
            'module_id' => $moduleId,
            'content_id' => $contentId,
            'assessment_id' => $assessmentId,
            'bank_id' => $bankId,
            'question_ids' => $questionIds,
            'correct_option_ids_by_question' => $correctByQuestion,
        ];
    }

    /**
     * Synthetic admitted Active enrolment (bypasses Mode A HTTP for player tests).
     *
     * @return array{enrolment_id: int, application_id: int, payment_id: int, batch_id: int}
     */
    public static function seedActiveEnrolment(
        int $userId,
        int $courseId,
        int $versionId,
        array $batchOverrides = [],
    ): array {
        $batchId = self::seedBatch($versionId, array_merge([
            'starts_at' => (new \DateTimeImmutable('-7 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'ends_at' => (new \DateTimeImmutable('+60 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'applications_open_at' => (new \DateTimeImmutable('-30 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            'applications_close_at' => (new \DateTimeImmutable('+14 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ], $batchOverrides));

        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $suffix = bin2hex(random_bytes(4));

        $pdo->prepare(
            'INSERT INTO applications (
                application_number, user_id, course_version_id, batch_id, status, state_version,
                submitted_at, created_at, updated_at
             ) VALUES (
                :application_number, :user_id, :course_version_id, :batch_id, :status, 1,
                :submitted_at, :created_at, :updated_at
             )',
        )->execute([
            'application_number' => 'APP-PLAY-' . strtoupper($suffix),
            'user_id' => $userId,
            'course_version_id' => $versionId,
            'batch_id' => $batchId,
            'status' => \Academy\Domain\Admissions\ApplicationStatus::ADMITTED,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $applicationId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payments (
                public_reference, application_id, user_id, provider, provider_order_id, provider_payment_id,
                base_fee_minor, gst_minor, amount_minor, currency, gst_rate_percent,
                course_version_id, batch_id, fee_override_applied, status, failure_code, failure_category,
                attempt_number, idempotency_key, row_version, successful_marker,
                initiated_at, provider_order_bound_at, authorized_at, captured_at,
                failed_at, expired_at, reconciled_at, created_at, updated_at
             ) VALUES (
                :public_reference, :application_id, :user_id, :provider, :provider_order_id, :provider_payment_id,
                100000, 18000, 118000, :currency, 18.00,
                :course_version_id, :batch_id, NULL, :status, NULL, NULL,
                1, :idempotency_key, 1, 1,
                :initiated_at, :bound_at, :authorized_at, :captured_at,
                NULL, NULL, NULL, :created_at, :updated_at
             )',
        )->execute([
            'public_reference' => 'PAY-PLAY-' . strtoupper($suffix),
            'application_id' => $applicationId,
            'user_id' => $userId,
            'provider' => 'razorpay',
            'provider_order_id' => 'order_play_' . $suffix,
            'provider_payment_id' => 'pay_play_' . $suffix,
            'currency' => 'INR',
            'course_version_id' => $versionId,
            'batch_id' => $batchId,
            'status' => \Academy\Domain\Payments\PaymentStatus::SUCCESSFUL,
            'idempotency_key' => 'play-' . $suffix,
            'initiated_at' => $now,
            'bound_at' => $now,
            'authorized_at' => $now,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO enrolments (
                public_reference, application_id, user_id, course_id, course_version_id, batch_id, payment_id,
                lifecycle_status, academic_status, admitted_at, activated_at, access_expires_at,
                row_version, created_at, updated_at
             ) VALUES (
                :public_reference, :application_id, :user_id, :course_id, :course_version_id, :batch_id, :payment_id,
                :lifecycle_status, :academic_status, :admitted_at, :activated_at, NULL,
                1, :created_at, :updated_at
             )',
        )->execute([
            'public_reference' => 'ENR-PLAY-' . strtoupper($suffix),
            'application_id' => $applicationId,
            'user_id' => $userId,
            'course_id' => $courseId,
            'course_version_id' => $versionId,
            'batch_id' => $batchId,
            'payment_id' => $paymentId,
            'lifecycle_status' => \Academy\Domain\Learning\EnrolmentLifecycleStatus::ACTIVE,
            'academic_status' => \Academy\Domain\Learning\EnrolmentAcademicStatus::IN_PROGRESS,
            'admitted_at' => $now,
            'activated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $enrolmentId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE payments SET enrolment_id = :enrolment_id WHERE payment_id = :payment_id')
            ->execute(['enrolment_id' => $enrolmentId, 'payment_id' => $paymentId]);

        return [
            'enrolment_id' => $enrolmentId,
            'application_id' => $applicationId,
            'payment_id' => $paymentId,
            'batch_id' => $batchId,
        ];
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
