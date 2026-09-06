<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * WP-L9 Phase 1 learning demo — local/testing/ci/uat only.
 *
 * Idempotent by stable course_code. Creates a published CourseVersion with
 * modules, text lessons, MCQ assessment + bank links, and an open batch whose
 * starts_at is in the past so Admit → Active enrolment works for the player.
 *
 * Learner progress / certificates are seeded by UatSeedService after personas exist.
 */
final class WpL9Phase1LearningDemoSeeder extends AbstractSeed
{
    public const COURSE_CODE = 'PHASE1-DEMO-CME-101';
    public const BATCH_CODE = 'PHASE1-DEMO-CME-101-ACTIVE';
    public const SLUG = 'phase1-demo-obesity-learning';

    public function run(): void
    {
        $env = $this->currentEnv();
        if (in_array($env, ['production', 'staging'], true)) {
            throw new RuntimeException('WpL9Phase1LearningDemoSeeder must never run in ' . $env . '.');
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = $this->nowUtc();

        if ($this->findCourseId($pdo, self::COURSE_CODE) !== null) {
            $this->ensureActiveBatchIfMissing($pdo, $now);

            return;
        }

        $courseId = $this->insertCourse($pdo, $now);
        $versionId = $this->insertDraftVersion($pdo, $courseId, $now);

        $this->insertEligibilityRule($pdo, $versionId, $now);
        $this->insertDocumentRequirement($pdo, $versionId, $now);

        $module1 = $this->insertModule($pdo, $versionId, 1, 'Foundations of metabolic health', $now);
        $module2 = $this->insertModule($pdo, $versionId, 2, 'Assessment and completion', $now);

        $this->insertTextLesson(
            $pdo,
            $module1,
            1,
            'Introduction to obesity assessment',
            "This lesson introduces guideline-based assessment of adult obesity.\n\nKey points:\n- Measure BMI and waist circumference\n- Screen for metabolic complications\n- Document shared decision-making",
            $now,
        );
        $this->insertTextLesson(
            $pdo,
            $module1,
            2,
            'Lifestyle counselling essentials',
            "Lifestyle counselling remains first-line care.\n\nCover nutrition, physical activity, sleep, and behavioural support in a brief clinic visit.",
            $now,
        );
        $mcqContentId = $this->insertMcqContent($pdo, $module2, 1, 'Module knowledge check', $now);

        $bankId = $this->ensureQuestionBank($pdo, $courseId, $now);
        $questionIds = [];
        for ($i = 1; $i <= 5; ++$i) {
            $questionIds[] = $this->insertQuestionWithOptions($pdo, $bankId, $i, $now);
        }

        $assessmentId = $this->insertAssessment($pdo, $mcqContentId, $now);
        foreach ($questionIds as $index => $questionId) {
            $this->insertAssessmentLink($pdo, $assessmentId, $questionId, $index + 1, $now);
        }

        $this->publishVersion($pdo, $versionId, $now);
        $this->setCoursePublishedVersion($pdo, $courseId, $versionId, $now);
        $this->insertActiveOpenBatch($pdo, $versionId, $now);
    }

    private function ensureActiveBatchIfMissing(PDO $pdo, string $now): void
    {
        $existing = $pdo->prepare('SELECT batch_id FROM batches WHERE batch_code = :code LIMIT 1');
        $existing->execute(['code' => self::BATCH_CODE]);
        if ($existing->fetchColumn() !== false) {
            return;
        }

        $courseId = $this->findCourseId($pdo, self::COURSE_CODE);
        if ($courseId === null) {
            return;
        }
        $version = $pdo->prepare(
            'SELECT current_published_version_id FROM courses WHERE course_id = :id LIMIT 1',
        );
        $version->execute(['id' => $courseId]);
        $versionId = $version->fetchColumn();
        if ($versionId === false || $versionId === null) {
            return;
        }

        $this->insertActiveOpenBatch($pdo, (int) $versionId, $now);
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

    private function daysFromNow(int $days): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d H:i:s.u');
    }

    private function findCourseId(PDO $pdo, string $courseCode): ?int
    {
        $stmt = $pdo->prepare('SELECT course_id FROM courses WHERE course_code = :course_code');
        $stmt->execute(['course_code' => $courseCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['course_id'];
    }

    private function insertCourse(PDO $pdo, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO courses (
                course_code, slug, master_title, status, current_published_version_id, created_at, updated_at
            ) VALUES (
                :course_code, :slug, :master_title, :status, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_code' => self::COURSE_CODE,
            'slug' => self::SLUG,
            'master_title' => 'Phase 1 Demo — Obesity Learning Pathway',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertDraftVersion(PDO $pdo, int $courseId, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO course_versions (
                course_id, version_number, title, description, learning_objectives, intended_audience,
                syllabus_summary, admission_mode, delivery_type, duration_text, validity_period_days,
                standard_fee, gst_rate, currency, certificate_type, faq_json, status,
                published_at, locked_at, locked_reason, created_at, updated_at
            ) VALUES (
                :course_id, 1, :title, :description, :learning_objectives, :intended_audience,
                :syllabus_summary, :admission_mode, :delivery_type, :duration_text, :validity_period_days,
                :standard_fee, :gst_rate, :currency, :certificate_type, :faq_json, :status,
                NULL, NULL, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => 'Phase 1 Demo — Obesity Learning Pathway (v1)',
            'description' => 'Short Continuing Medical Education pathway used for the Phase 1 customer demo: text lessons, an MCQ assessment, and a completion certificate after all mandatory items are passed.',
            'learning_objectives' => 'Complete foundational lessons; pass the module knowledge check; receive a completion certificate when eligible.',
            'intended_audience' => 'Demo facilitators and Product Owner walkthroughs for Academy LMS Phase 1.',
            'syllabus_summary' => 'Module 1: Foundations (two text lessons). Module 2: Knowledge check (MCQ assessment).',
            'admission_mode' => 'A',
            'delivery_type' => 'online',
            'duration_text' => 'Self-paced Phase 1 demo pathway',
            'validity_period_days' => 365,
            'standard_fee' => '5000.00',
            'gst_rate' => '18.00',
            'currency' => 'INR',
            'certificate_type' => 'Certificate of Completion',
            'faq_json' => json_encode([
                [
                    'question' => 'Does this course include the learning player?',
                    'answer' => 'Yes. This Phase 1 demo course includes lessons, an MCQ assessment, and certificate verification.',
                ],
            ], JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertEligibilityRule(PDO $pdo, int $versionId, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO eligibility_rules (
                course_version_id, field, operator, value, logic_group, display_label, sort_order, created_at, updated_at
            ) VALUES (
                :version_id, :field, :operator, :value, :logic_group, :display_label, 1, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'field' => 'profession',
            'operator' => 'in',
            'value' => 'doctor,nurse,allied_professional',
            'logic_group' => 'AND',
            'display_label' => 'Must be a registered doctor, nurse or allied medical professional.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertDocumentRequirement(PDO $pdo, int $versionId, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO course_document_requirements (
                course_version_id, document_name, description, mandatory_flag, accepted_file_types,
                max_size_bytes, single_or_multiple, reuse_allowed, reviewer_instructions, sort_order,
                created_at, updated_at
            ) VALUES (
                :version_id, :document_name, :description, 1, :accepted_file_types,
                :max_size_bytes, :single_or_multiple, 1, :reviewer_instructions, 1,
                :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'document_name' => 'Medical council registration certificate',
            'description' => 'A clear scan or photo of your current medical council registration certificate.',
            'accepted_file_types' => 'pdf,jpg,jpeg,png',
            'max_size_bytes' => 10485760,
            'single_or_multiple' => 'single',
            'reviewer_instructions' => 'Confirm registration number matches the applicant profile.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertModule(PDO $pdo, int $versionId, int $sequence, string $title, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag, release_rule,
                prerequisite_module_id, created_at, updated_at
            ) VALUES (
                :version_id, :sequence, :title, :description, 1, :release_rule,
                NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'sequence' => $sequence,
            'title' => $title,
            'description' => $title,
            'release_rule' => $sequence === 1 ? 'immediate' : 'sequential',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertTextLesson(
        PDO $pdo,
        int $moduleId,
        int $sequence,
        string $title,
        string $body,
        string $now,
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, object_key,
                mandatory_flag, completion_rule, created_at, updated_at
            ) VALUES (
                :module_id, :sequence, :content_type, :title, :body_text, NULL,
                1, :completion_rule, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'sequence' => $sequence,
            'content_type' => 'text_lesson',
            'title' => $title,
            'body_text' => $body,
            'completion_rule' => 'mark_complete',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertMcqContent(PDO $pdo, int $moduleId, int $sequence, string $title, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, object_key,
                mandatory_flag, completion_rule, created_at, updated_at
            ) VALUES (
                :module_id, :sequence, :content_type, :title, NULL, NULL,
                1, :completion_rule, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'module_id' => $moduleId,
            'sequence' => $sequence,
            'content_type' => 'mcq_assessment',
            'title' => $title,
            'completion_rule' => 'assessment_passed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function ensureQuestionBank(PDO $pdo, int $courseId, string $now): int
    {
        $existing = $pdo->prepare('SELECT bank_id FROM question_banks WHERE course_id = :course_id LIMIT 1');
        $existing->execute(['course_id' => $courseId]);
        $bankId = $existing->fetchColumn();
        if ($bankId !== false) {
            return (int) $bankId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO question_banks (course_id, title, created_at, updated_at)
             VALUES (:course_id, :title, :created_at, :updated_at)',
        );
        $stmt->execute([
            'course_id' => $courseId,
            'title' => 'Phase 1 demo question bank',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertQuestionWithOptions(PDO $pdo, int $bankId, int $number, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO questions (
                bank_id, question_type, stem, marks, status, version, created_at, updated_at
            ) VALUES (
                :bank_id, :question_type, :stem, :marks, :status, 1, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'bank_id' => $bankId,
            'question_type' => 'mcq_single',
            'stem' => 'Phase 1 demo question ' . $number . ': which statement is correct?',
            'marks' => '1.00',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $questionId = (int) $pdo->lastInsertId();

        $opt = $pdo->prepare(
            'INSERT INTO question_options (
                question_id, sequence, option_text, is_correct, created_at, updated_at
            ) VALUES (
                :question_id, :sequence, :option_text, :is_correct, :created_at, :updated_at
            )',
        );
        $opt->execute([
            'question_id' => $questionId,
            'sequence' => 1,
            'option_text' => 'Correct clinical statement ' . $number,
            'is_correct' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $opt->execute([
            'question_id' => $questionId,
            'sequence' => 2,
            'option_text' => 'Incorrect distractor ' . $number,
            'is_correct' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $questionId;
    }

    private function insertAssessment(PDO $pdo, int $contentId, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO assessments (
                content_id, title, questions_per_attempt, pass_threshold_percent, time_limit_seconds,
                max_attempts, cooldown_seconds, randomise_questions, randomise_options,
                created_at, updated_at
            ) VALUES (
                :content_id, :title, :questions_per_attempt, :pass_threshold_percent, NULL,
                :max_attempts, NULL, 0, 0, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'content_id' => $contentId,
            'title' => 'Module knowledge check',
            'questions_per_attempt' => 5,
            'pass_threshold_percent' => '60.00',
            'max_attempts' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertAssessmentLink(
        PDO $pdo,
        int $assessmentId,
        int $questionId,
        int $sequence,
        string $now,
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO assessment_question_links (
                assessment_id, question_id, sequence, created_at, updated_at
            ) VALUES (
                :assessment_id, :question_id, :sequence, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'assessment_id' => $assessmentId,
            'question_id' => $questionId,
            'sequence' => $sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function publishVersion(PDO $pdo, int $versionId, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE course_versions
             SET status = :status, published_at = :published_at, locked_at = :locked_at,
                 locked_reason = :locked_reason, updated_at = :updated_at
             WHERE version_id = :id',
        );
        $stmt->execute([
            'status' => 'published',
            'published_at' => $now,
            'locked_at' => $now,
            'locked_reason' => 'published',
            'updated_at' => $now,
            'id' => $versionId,
        ]);
    }

    private function setCoursePublishedVersion(PDO $pdo, int $courseId, int $versionId, string $now): void
    {
        $stmt = $pdo->prepare(
            'UPDATE courses
             SET current_published_version_id = :version_id, updated_at = :updated_at
             WHERE course_id = :course_id',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'updated_at' => $now,
            'course_id' => $courseId,
        ]);
    }

    private function insertActiveOpenBatch(PDO $pdo, int $versionId, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO batches (
                course_version_id, batch_code, name, starts_at, ends_at,
                applications_open_at, applications_close_at, min_capacity, max_capacity,
                delivery_mode, venue_or_online_details, timezone, fee_override, currency,
                status, access_expires_at, created_at, updated_at
            ) VALUES (
                :version_id, :batch_code, :name, :starts_at, :ends_at,
                :applications_open_at, :applications_close_at, :min_capacity, :max_capacity,
                :delivery_mode, :venue_or_online_details, :timezone, NULL, :currency,
                :status, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'batch_code' => self::BATCH_CODE,
            'name' => 'Phase 1 demo cohort (active learning)',
            'starts_at' => $this->daysFromNow(-7),
            'ends_at' => $this->daysFromNow(60),
            'applications_open_at' => $this->daysFromNow(-30),
            'applications_close_at' => $this->daysFromNow(30),
            'min_capacity' => 5,
            'max_capacity' => 40,
            'delivery_mode' => 'online',
            'venue_or_online_details' => 'Self-paced online Phase 1 demo pathway.',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'status' => 'open_for_applications',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
