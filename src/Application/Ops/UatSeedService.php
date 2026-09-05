<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Deterministic, idempotent UAT seed (RC-01).
 * Gated to local|testing|ci|uat. Never runs in staging/production.
 *
 * Interactive journey account: learner@uat.example.test (no conflicting application).
 * Scenario accounts hold representative states for list/detail UAT.
 */
final class UatSeedService
{
    public const EMAIL_DOMAIN = 'uat.example.test';
    public const PASSWORD_ENV = 'UAT_SEED_PASSWORD';
    public const DEFAULT_PASSWORD = 'Uat-Demo-Passw0rd!';
    public const MARKER_PREFIX = 'UAT-';
    public const DEMO_BATCH_CODE = 'WP02-DEMO-OBESITY-101-OPEN';
    public const DEMO_COURSE_CODE = 'WP02-DEMO-OBESITY-101';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnvironmentCapability $capability,
    ) {
    }

    /**
     * @return array{personas: int, catalogue: bool, applications: int, notifications: int, summary: list<string>}
     */
    public function seed(): array
    {
        $this->assertAllowed();
        $pdo = $this->connections->connection();
        $now = $this->now();
        $hash = password_hash($this->password(), PASSWORD_ARGON2ID);

        $summary = [];
        $personaCount = $this->seedPersonas($pdo, $hash, $now, $summary);
        $catalogueOk = $this->ensureCatalogue($pdo, $summary);
        $applicationCount = $catalogueOk
            ? $this->seedScenarioApplications($pdo, $hash, $now, $summary)
            : 0;
        $notificationCount = $this->seedNotificationSamples($pdo, $now, $summary);

        return [
            'personas' => $personaCount,
            'catalogue' => $catalogueOk,
            'applications' => $applicationCount,
            'notifications' => $notificationCount,
            'summary' => $summary,
        ];
    }

    private function assertAllowed(): void
    {
        if (!$this->capability->allowsUatSeedAndReset()) {
            throw new RuntimeException(
                'uat:seed refused for APP_ENV=' . $this->capability->name() . ' (allowed: local|testing|ci|uat).',
            );
        }
    }

    /**
     * @param list<string> $summary
     */
    private function seedPersonas(PDO $pdo, string $hash, string $now, array &$summary): int
    {
        $personas = [
            ['email' => 'learner@' . self::EMAIL_DOMAIN, 'mobile' => '+919900000001', 'roles' => [RoleKeys::APPLICANT], 'label' => 'Learner', 'first_name' => 'Ananya', 'last_name' => 'Sharma'],
            ['email' => 'reviewer@' . self::EMAIL_DOMAIN, 'mobile' => '+919900000002', 'roles' => [RoleKeys::CREDENTIAL_REVIEWER], 'label' => 'Reviewer'],
            ['email' => 'finance@' . self::EMAIL_DOMAIN, 'mobile' => '+919900000003', 'roles' => [RoleKeys::FINANCE_ADMIN], 'label' => 'Finance'],
            ['email' => 'course-admin@' . self::EMAIL_DOMAIN, 'mobile' => '+919900000006', 'roles' => [RoleKeys::COURSE_ADMIN], 'label' => 'Course Admin'],
            ['email' => 'ops@' . self::EMAIL_DOMAIN, 'mobile' => '+919900000004', 'roles' => [RoleKeys::SUPER_ADMIN], 'label' => 'Notification Operations'],
            ['email' => 'multi@' . self::EMAIL_DOMAIN, 'mobile' => '+919900000005', 'roles' => [RoleKeys::APPLICANT, RoleKeys::CREDENTIAL_REVIEWER], 'label' => 'Multi-permission', 'first_name' => 'Rohan', 'last_name' => 'Mehta'],
        ];

        $count = 0;
        foreach ($personas as $persona) {
            $userId = $this->ensureUser($pdo, $persona['email'], $persona['mobile'], $hash, $now);
            foreach ($persona['roles'] as $roleKey) {
                $this->ensureRole($pdo, $userId, $roleKey, $now);
            }
            if (in_array(RoleKeys::APPLICANT, $persona['roles'], true)) {
                $this->ensureLearnerProfile(
                    $pdo,
                    $userId,
                    $now,
                    $persona['first_name'] ?? 'Demo',
                    $persona['last_name'] ?? 'Learner',
                );
            }
            if (in_array(RoleKeys::CREDENTIAL_REVIEWER, $persona['roles'], true)) {
                $this->ensureReviewerBatchScope($pdo, $userId, $now);
            }
            if (in_array(RoleKeys::COURSE_ADMIN, $persona['roles'], true)) {
                $this->ensureCourseAdminDemoCourseScope($pdo, $userId, $now);
            }
            $summary[] = 'persona:' . $persona['label'] . '=' . $persona['email'];
            ++$count;
        }

        return $count;
    }

    /**
     * @param list<string> $summary
     */
    private function ensureCatalogue(PDO $pdo, array &$summary): bool
    {
        $stmt = $pdo->prepare('SELECT course_id FROM courses WHERE course_code = :code LIMIT 1');
        $stmt->execute(['code' => self::DEMO_COURSE_CODE]);
        if ($stmt->fetchColumn() !== false) {
            $summary[] = 'catalogue:present';

            return true;
        }

        $summary[] = 'catalogue:missing — run: vendor/bin/phinx seed:run -s Wp02DemoCatalogueSeeder -e development';

        return false;
    }

    /**
     * @param list<string> $summary
     */
    private function seedScenarioApplications(PDO $pdo, string $hash, string $now, array &$summary): int
    {
        $batchStmt = $pdo->prepare(
            'SELECT b.batch_id, b.course_version_id, cv.course_id
             FROM batches b
             INNER JOIN course_versions cv ON cv.version_id = b.course_version_id
             WHERE b.batch_code = :code LIMIT 1',
        );
        $batchStmt->execute(['code' => self::DEMO_BATCH_CODE]);
        $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
        if ($batch === false) {
            $summary[] = 'applications:skipped-no-batch';

            return 0;
        }

        $batchId = (int) $batch['batch_id'];
        $versionId = (int) $batch['course_version_id'];
        $courseId = (int) $batch['course_id'];

        $scenarios = [
            ['slug' => 'draft', 'number' => self::MARKER_PREFIX . 'DRAFT-001', 'status' => 'draft', 'mobile' => '+919900001001'],
            ['slug' => 'review', 'number' => self::MARKER_PREFIX . 'REVIEW-001', 'status' => 'under_review', 'mobile' => '+919900001002'],
            ['slug' => 'correct', 'number' => self::MARKER_PREFIX . 'CORRECT-001', 'status' => 'resubmission_requested', 'mobile' => '+919900001003'],
            ['slug' => 'paypend', 'number' => self::MARKER_PREFIX . 'PAYPEND-001', 'status' => 'payment_pending', 'mobile' => '+919900001004', 'payment' => 'pending'],
            // In-flight pending payment — payment-result shows "Confirming payment…"
            ['slug' => 'confirm', 'number' => self::MARKER_PREFIX . 'CONFIRM-001', 'status' => 'payment_pending', 'mobile' => '+919900001009', 'payment' => 'pending'],
            ['slug' => 'await', 'number' => self::MARKER_PREFIX . 'AWAIT-001', 'status' => 'awaiting_verification', 'mobile' => '+919900001005', 'payment' => 'reconciliation_pending'],
            ['slug' => 'admit-sched', 'number' => self::MARKER_PREFIX . 'ADMIT-SCHED-001', 'status' => 'admitted', 'mobile' => '+919900001006', 'payment' => 'successful', 'enrolment' => 'scheduled'],
            ['slug' => 'admit-active', 'number' => self::MARKER_PREFIX . 'ADMIT-ACTIVE-001', 'status' => 'admitted', 'mobile' => '+919900001007', 'payment' => 'successful', 'enrolment' => 'active'],
            ['slug' => 'reject', 'number' => self::MARKER_PREFIX . 'REJECT-001', 'status' => 'rejected', 'mobile' => '+919900001008'],
            // Successful payment already present + second reconciliation_pending (duplicate capture discussion)
            [
                'slug' => 'dup',
                'number' => self::MARKER_PREFIX . 'DUP-001',
                'status' => 'admitted',
                'mobile' => '+919900001010',
                'payment' => 'successful',
                'enrolment' => 'scheduled',
                'extra_payment' => 'reconciliation_pending',
            ],
            // Captured payment awaiting finance after capacity exhaustion
            [
                'slug' => 'fullbatch',
                'number' => self::MARKER_PREFIX . 'FULLBATCH-001',
                'status' => 'payment_pending',
                'mobile' => '+919900001011',
                'payment' => 'reconciliation_pending',
                'payment_failure' => 'capacity_exhausted_after_payment',
            ],
        ];

        $count = 0;
        foreach ($scenarios as $scenario) {
            $email = 'learner-' . $scenario['slug'] . '@' . self::EMAIL_DOMAIN;
            $userId = $this->ensureUser($pdo, $email, $scenario['mobile'], $hash, $now);
            $this->ensureRole($pdo, $userId, RoleKeys::APPLICANT, $now);
            $this->ensureLearnerProfile($pdo, $userId, $now, 'Demo', ucfirst($scenario['slug']));

            $applicationId = $this->ensureApplication(
                $pdo,
                $userId,
                $batchId,
                $versionId,
                $scenario['number'],
                $scenario['status'],
                $now,
            );

            $paymentId = null;
            if (isset($scenario['payment'])) {
                $paymentId = $this->ensurePayment(
                    $pdo,
                    $applicationId,
                    $userId,
                    $versionId,
                    $batchId,
                    $scenario['payment'],
                    $scenario['number'] . '-PAY',
                    $now,
                    $scenario['payment_failure'] ?? null,
                );
            }

            if (isset($scenario['extra_payment'])) {
                $this->ensurePayment(
                    $pdo,
                    $applicationId,
                    $userId,
                    $versionId,
                    $batchId,
                    $scenario['extra_payment'],
                    $scenario['number'] . '-PAY-DUP',
                    $now,
                    'duplicate_capture',
                );
            }

            if (isset($scenario['enrolment']) && $paymentId !== null) {
                $this->ensureEnrolment(
                    $pdo,
                    $applicationId,
                    $userId,
                    $courseId,
                    $versionId,
                    $batchId,
                    $paymentId,
                    $scenario['enrolment'],
                    $scenario['number'],
                    $now,
                );
            }

            $summary[] = 'application:' . $scenario['number'] . '=' . $scenario['status'];
            ++$count;
        }

        return $count;
    }

    /**
     * @param list<string> $summary
     */
    private function seedNotificationSamples(PDO $pdo, string $now, array &$summary): int
    {
        $userId = $this->userIdByEmail($pdo, 'learner@' . self::EMAIL_DOMAIN);
        if ($userId === null) {
            $summary[] = 'notifications:skipped-no-user';

            return 0;
        }

        $samples = [
            ['key' => 'uat.pending', 'status' => 'pending', 'failure' => null, 'dead' => false],
            ['key' => 'uat.delivered', 'status' => 'delivered', 'failure' => null, 'dead' => false],
            ['key' => 'uat.retryable', 'status' => 'failed', 'failure' => 'provider_transient', 'dead' => false],
            ['key' => 'uat.dead', 'status' => 'dead', 'failure' => 'provider_permanent', 'dead' => true],
        ];

        $count = 0;
        foreach ($samples as $sample) {
            $outboxId = $this->ensureOutboxMessage($pdo, 'uat.seed.' . $sample['key'], $now);
            $existing = $pdo->prepare(
                'SELECT notification_delivery_id FROM notification_deliveries
                 WHERE outbox_message_id = :oid AND channel = :channel AND template_key = :tpl LIMIT 1',
            );
            $existing->execute(['oid' => $outboxId, 'channel' => 'email', 'tpl' => $sample['key']]);
            if ($existing->fetchColumn() !== false) {
                ++$count;
                continue;
            }

            $insert = $pdo->prepare(
                'INSERT INTO notification_deliveries (
                    outbox_message_id, source_event_type, user_id, channel, template_key, template_version,
                    recipient_hash, recipient_masked, status, attempt_count, next_attempt_at,
                    provider_message_id, failure_category, delivered_at, dead_at, created_at, updated_at
                ) VALUES (
                    :oid, :event, :user_id, :channel, :tpl, 1,
                    :hash, :masked, :status, :attempts, :next_attempt,
                    :provider_id, :failure, :delivered_at, :dead_at, :created_at, :updated_at
                )',
            );
            $insert->execute([
                'oid' => $outboxId,
                'event' => 'uat.seed.demo',
                'user_id' => $userId,
                'channel' => 'email',
                'tpl' => $sample['key'],
                'hash' => hash('sha256', 'learner@' . self::EMAIL_DOMAIN),
                'masked' => 'lea***@uat.example.test',
                'status' => $sample['status'],
                'attempts' => $sample['status'] === 'pending' ? 0 : 3,
                'next_attempt' => $sample['status'] === 'failed' ? $now : null,
                'provider_id' => $sample['status'] === 'delivered' ? 'uat-provider-msg-1' : null,
                'failure' => $sample['failure'],
                'delivered_at' => $sample['status'] === 'delivered' ? $now : null,
                'dead_at' => $sample['dead'] ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $summary[] = 'notification:' . $sample['key'] . '=' . $sample['status'];
            ++$count;
        }

        return $count;
    }

    private function ensureUser(PDO $pdo, string $email, string $mobile, string $hash, string $now): int
    {
        $existing = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => $email]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (int) $row['user_id'];
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                :email, :verified, :mobile, :mobile_verified, :hash,
                :status, 0, NULL, 1,
                :password_changed, :terms_at, :terms_ver,
                :privacy_at, :privacy_ver, :tz, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'email' => $email,
            'verified' => $now,
            'mobile' => $mobile,
            'mobile_verified' => $now,
            'hash' => $hash,
            'status' => 'active',
            'password_changed' => $now,
            'terms_at' => $now,
            'terms_ver' => 'uat.terms.v1',
            'privacy_at' => $now,
            'privacy_ver' => 'uat.privacy.v1',
            'tz' => 'Asia/Kolkata',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function ensureRole(PDO $pdo, int $userId, string $roleKey, string $now): void
    {
        $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = :key LIMIT 1');
        $roleStmt->execute(['key' => $roleKey]);
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            throw new RuntimeException('Missing role: ' . $roleKey);
        }
        $roleId = (int) $role['role_id'];

        $check = $pdo->prepare(
            'SELECT user_role_id FROM user_roles
             WHERE user_id = :user_id AND role_id = :role_id AND current_marker = 1 LIMIT 1',
        );
        $check->execute(['user_id' => $userId, 'role_id' => $roleId]);
        if ($check->fetchColumn() !== false) {
            return;
        }

        $assign = $pdo->prepare(
            'INSERT INTO user_roles (
                user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
            ) VALUES (:user_id, :role_id, NULL, :assigned_at, 1, :created_at, :updated_at)',
        );
        $assign->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureLearnerProfile(
        PDO $pdo,
        int $userId,
        string $now,
        string $firstName = 'Demo',
        string $lastName = 'Learner',
    ): void {
        $existing = $pdo->prepare('SELECT learner_profile_id FROM learner_profiles WHERE user_id = :id LIMIT 1');
        $existing->execute(['id' => $userId]);
        if ($existing->fetchColumn() !== false) {
            $update = $pdo->prepare(
                'UPDATE learner_profiles SET
                    first_name = COALESCE(first_name, :first_name),
                    last_name = COALESCE(last_name, :last_name),
                    profession = COALESCE(profession, :profession),
                    medical_council_registration_number = COALESCE(medical_council_registration_number, :reg),
                    updated_at = :updated_at
                 WHERE user_id = :user_id',
            );
            $update->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'profession' => 'doctor',
                'reg' => 'DEMO-MCI-' . $userId,
                'updated_at' => $now,
                'user_id' => $userId,
            ]);

            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO learner_profiles (
                user_id, first_name, last_name, profession, medical_council_registration_number,
                row_version, created_at, updated_at
            ) VALUES (
                :user_id, :first_name, :last_name, :profession, :reg,
                1, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'profession' => 'doctor',
            'reg' => 'DEMO-MCI-' . $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureReviewerBatchScope(PDO $pdo, int $userId, string $now): void
    {
        $batch = $pdo->prepare('SELECT batch_id FROM batches WHERE batch_code = :code LIMIT 1');
        $batch->execute(['code' => self::DEMO_BATCH_CODE]);
        $batchId = $batch->fetchColumn();
        if ($batchId === false) {
            return;
        }

        $check = $pdo->prepare(
            'SELECT scope_assignment_id FROM reviewer_scope_assignments
             WHERE reviewer_user_id = :user_id AND scope_type = :type AND batch_id = :batch_id
               AND revoked_at IS NULL LIMIT 1',
        );
        $check->execute([
            'user_id' => $userId,
            'type' => 'batch',
            'batch_id' => (int) $batchId,
        ]);
        if ($check->fetchColumn() !== false) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO reviewer_scope_assignments (
                reviewer_user_id, scope_type, course_id, course_version_id, batch_id,
                include_future_versions, effective_from, effective_to,
                created_by_user_id, revoked_at, revoked_by_user_id, created_at, updated_at
            ) VALUES (
                :user_id, :type, NULL, NULL, :batch_id,
                0, :effective_from, NULL,
                :created_by, NULL, NULL, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'user_id' => $userId,
            'type' => 'batch',
            'batch_id' => (int) $batchId,
            'effective_from' => $now,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureCourseAdminDemoCourseScope(PDO $pdo, int $userId, string $now): void
    {
        $course = $pdo->prepare('SELECT course_id FROM courses WHERE course_code = :code LIMIT 1');
        $course->execute(['code' => self::DEMO_COURSE_CODE]);
        $courseId = $course->fetchColumn();
        if ($courseId === false) {
            return;
        }

        $check = $pdo->prepare(
            'SELECT scope_assignment_id FROM course_admin_scope_assignments
             WHERE admin_user_id = :user_id AND scope_type = :type AND course_id = :course_id
               AND revoked_at IS NULL LIMIT 1',
        );
        $check->execute([
            'user_id' => $userId,
            'type' => 'course',
            'course_id' => (int) $courseId,
        ]);
        if ($check->fetchColumn() !== false) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO course_admin_scope_assignments (
                admin_user_id, scope_type, course_id, course_version_id,
                include_future_versions, effective_from, effective_to,
                created_by_user_id, revoked_at, revoked_by_user_id, created_at, updated_at
            ) VALUES (
                :user_id, :type, :course_id, NULL,
                1, :effective_from, NULL,
                :created_by, NULL, NULL, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'user_id' => $userId,
            'type' => 'course',
            'course_id' => (int) $courseId,
            'effective_from' => $now,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureApplication(
        PDO $pdo,
        int $userId,
        int $batchId,
        int $versionId,
        string $number,
        string $status,
        string $now,
    ): int {
        $existing = $pdo->prepare('SELECT application_id FROM applications WHERE application_number = :num LIMIT 1');
        $existing->execute(['num' => $number]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (int) $row['application_id'];
        }

        $byUserBatch = $pdo->prepare(
            'SELECT application_id FROM applications WHERE user_id = :user_id AND batch_id = :batch_id LIMIT 1',
        );
        $byUserBatch->execute(['user_id' => $userId, 'batch_id' => $batchId]);
        $existingPair = $byUserBatch->fetch(PDO::FETCH_ASSOC);
        if ($existingPair !== false) {
            return (int) $existingPair['application_id'];
        }

        $submitted = $status === 'draft' ? null : $now;
        $insert = $pdo->prepare(
            'INSERT INTO applications (
                application_number, user_id, batch_id, course_version_id, status, state_version,
                submitted_at, created_at, updated_at
            ) VALUES (
                :number, :user_id, :batch_id, :version_id, :status, 1,
                :submitted, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'number' => $number,
            'user_id' => $userId,
            'batch_id' => $batchId,
            'version_id' => $versionId,
            'status' => $status,
            'submitted' => $submitted,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function ensurePayment(
        PDO $pdo,
        int $applicationId,
        int $userId,
        int $versionId,
        int $batchId,
        string $status,
        string $publicRef,
        string $now,
        ?string $failureCategory = null,
    ): int {
        $existing = $pdo->prepare('SELECT payment_id FROM payments WHERE public_reference = :ref LIMIT 1');
        $existing->execute(['ref' => $publicRef]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $successfulMarker = $status === 'successful' ? 1 : null;
        $attemptNumber = str_contains($publicRef, '-PAY-DUP') ? 2 : 1;
        $insert = $pdo->prepare(
            'INSERT INTO payments (
                public_reference, application_id, user_id, provider, provider_order_id, provider_payment_id,
                base_fee_minor, gst_minor, amount_minor, currency, gst_rate_percent,
                course_version_id, batch_id, fee_override_applied, status,
                failure_code, failure_category, attempt_number, idempotency_key, row_version,
                successful_marker, initiated_at, provider_order_bound_at, authorized_at, captured_at,
                failed_at, expired_at, reconciled_at, created_at, updated_at
            ) VALUES (
                :ref, :application_id, :user_id, :provider, :order_id, :payment_id,
                :base_fee, :gst, :amount, :currency, :gst_rate,
                :version_id, :batch_id, NULL, :status,
                :failure_code, :failure_category, :attempt_number, :idempotency, 1,
                :successful_marker, :initiated_at, :bound_at, NULL, :captured_at,
                NULL, NULL, :reconciled_at, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'ref' => $publicRef,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'provider' => 'razorpay',
            'order_id' => 'order_' . substr(hash('sha256', $publicRef), 0, 14),
            'payment_id' => in_array($status, ['successful', 'reconciliation_pending'], true)
                ? 'pay_' . substr(hash('sha256', $publicRef), 0, 14)
                : null,
            'base_fee' => 1500000,
            'gst' => 270000,
            'amount' => 1770000,
            'currency' => 'INR',
            'gst_rate' => '18.00',
            'version_id' => $versionId,
            'batch_id' => $batchId,
            'status' => $status,
            'failure_code' => $failureCategory,
            'failure_category' => $failureCategory,
            'attempt_number' => $attemptNumber,
            'idempotency' => 'uat:' . $publicRef,
            'successful_marker' => $successfulMarker,
            'initiated_at' => $now,
            'bound_at' => $now,
            'captured_at' => in_array($status, ['successful', 'reconciliation_pending'], true) ? $now : null,
            'reconciled_at' => $status === 'reconciliation_pending' ? null : ($status === 'successful' ? $now : null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function ensureEnrolment(
        PDO $pdo,
        int $applicationId,
        int $userId,
        int $courseId,
        int $versionId,
        int $batchId,
        int $paymentId,
        string $lifecycle,
        string $appNumber,
        string $now,
    ): void {
        $existing = $pdo->prepare('SELECT enrolment_id FROM enrolments WHERE application_id = :id LIMIT 1');
        $existing->execute(['id' => $applicationId]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $ref = self::MARKER_PREFIX . 'ENR-' . substr(hash('sha256', $appNumber), 0, 10);
        $insert = $pdo->prepare(
            'INSERT INTO enrolments (
                public_reference, application_id, user_id, course_id, course_version_id, batch_id, payment_id,
                lifecycle_status, academic_status, admitted_at, activated_at, access_expires_at,
                row_version, created_at, updated_at
            ) VALUES (
                :ref, :application_id, :user_id, :course_id, :version_id, :batch_id, :payment_id,
                :lifecycle, :academic, :admitted_at, :activated_at, NULL,
                1, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'ref' => $ref,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'course_id' => $courseId,
            'version_id' => $versionId,
            'batch_id' => $batchId,
            'payment_id' => $paymentId,
            'lifecycle' => $lifecycle,
            'academic' => $lifecycle === 'active' ? 'in_progress' : 'not_started',
            'admitted_at' => $now,
            'activated_at' => $lifecycle === 'active' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureOutboxMessage(PDO $pdo, string $idempotencyKey, string $now): int
    {
        $existing = $pdo->prepare(
            'SELECT outbox_message_id FROM outbox_messages WHERE idempotency_key = :key LIMIT 1',
        );
        $existing->execute(['key' => $idempotencyKey]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $pdo->prepare(
            'INSERT INTO outbox_messages (
                event_type, aggregate_type, aggregate_id, payload, idempotency_key,
                status, attempt_count, available_at, created_at, updated_at
            ) VALUES (
                :event_type, :aggregate_type, :aggregate_id, CAST(:payload AS JSON), :idempotency_key,
                :status, 0, :available_at, :created_at, :updated_at
            )',
        );
        $insert->execute([
            'event_type' => 'uat.seed.demo',
            'aggregate_type' => 'uat',
            'aggregate_id' => '0',
            'payload' => '{}',
            'idempotency_key' => $idempotencyKey,
            'status' => 'published',
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function userIdByEmail(PDO $pdo, string $email): ?int
    {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function password(): string
    {
        $fromEnv = getenv(self::PASSWORD_ENV) ?: ($_ENV[self::PASSWORD_ENV] ?? '');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return self::DEFAULT_PASSWORD;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
