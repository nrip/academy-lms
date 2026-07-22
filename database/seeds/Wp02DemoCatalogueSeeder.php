<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * WP-02 demo catalogue — local/testing/ci only. Idempotent by stable
 * `course_code` / `batch_code`: re-running this seeder is a no-op once the
 * rows exist. Never auto-run in production/staging (see run() guard).
 *
 * Seed order mirrors WP02_IMPLEMENTATION_NOTE.md "Demo seed" exactly:
 *   1. INSERT course (current_published_version_id NULL)
 *   2. INSERT course_version as draft, locked_at NULL, with all content
 *   3. INSERT eligibility_rules + course_document_requirements
 *   4. UPDATE course_version -> published + locked (OLD.locked_at was NULL,
 *      so the immutability trigger's guard body never runs for this UPDATE)
 *   5. UPDATE course.current_published_version_id
 *   6. INSERT batches referencing the now-locked version
 */
final class Wp02DemoCatalogueSeeder extends AbstractSeed
{
    public function run(): void
    {
        $env = $this->currentEnv();
        if (in_array($env, ['production', 'staging'], true)) {
            throw new RuntimeException('Wp02DemoCatalogueSeeder must never run in ' . $env . '.');
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = $this->nowUtc();

        $this->seedObesityFoundationsCourse($pdo, $now);
        $this->seedMetabolicHealthCourse($pdo, $now);
    }

    private function seedObesityFoundationsCourse(PDO $pdo, string $now): void
    {
        $courseCode = 'WP02-DEMO-OBESITY-101';
        $courseId = $this->findCourseId($pdo, $courseCode);

        if ($courseId === null) {
            $courseId = $this->insertCourse($pdo, $courseCode, 'obesity-management-foundations', 'Foundations of Obesity Management', $now);
            $versionId = $this->insertDraftVersion($pdo, $courseId, [
                'version_number' => 1,
                'title' => 'Foundations of Obesity Management — Batch 2026',
                'description' => 'A foundational Continuing Medical Education course covering evidence-based assessment and management of obesity for doctors, nurses and allied medical professionals.',
                'learning_objectives' => 'Diagnose obesity using current clinical guidelines; design individualised management plans; counsel patients on lifestyle, pharmacological and surgical options.',
                'intended_audience' => 'Doctors, nurses and allied medical professionals managing adult patients with obesity or metabolic risk factors.',
                'syllabus_summary' => 'Module 1: Pathophysiology of obesity. Module 2: Clinical assessment. Module 3: Lifestyle and behavioural interventions. Module 4: Pharmacotherapy. Module 5: Surgical referral pathways.',
                'admission_mode' => 'A',
                'delivery_type' => 'online',
                'duration_text' => '6 weeks, self-paced with weekly live sessions',
                'validity_period_days' => 365,
                'standard_fee' => '15000.00',
                'gst_rate' => '18.00',
                'currency' => 'INR',
                'certificate_type' => 'Certificate of Completion',
                'faq_json' => json_encode([
                    ['question' => 'Is this course accredited?', 'answer' => 'Yes, it carries CME credit points.'],
                ], JSON_THROW_ON_ERROR),
            ], $now);

            $this->insertEligibilityRule($pdo, $versionId, [
                'field' => 'profession',
                'operator' => 'in',
                'value' => 'doctor,nurse,allied_professional',
                'logic_group' => 'AND',
                'display_label' => 'Must be a registered doctor, nurse or allied medical professional.',
                'sort_order' => 1,
            ], $now);
            $this->insertEligibilityRule($pdo, $versionId, [
                'field' => 'medical_council_registration_number',
                'operator' => 'not_empty',
                'value' => 'true',
                'logic_group' => 'AND',
                'display_label' => 'Must hold a valid medical council registration.',
                'sort_order' => 2,
            ], $now);

            $this->insertDocumentRequirement($pdo, $versionId, [
                'document_name' => 'Medical council registration certificate',
                'description' => 'A clear scan or photo of your current medical council registration certificate.',
                'mandatory_flag' => 1,
                'accepted_file_types' => 'pdf,jpg,jpeg,png',
                'max_size_bytes' => 10485760,
                'single_or_multiple' => 'single',
                'reuse_allowed' => 1,
                'reviewer_instructions' => 'Confirm registration number matches the applicant profile.',
                'sort_order' => 1,
            ], $now);
            $this->insertDocumentRequirement($pdo, $versionId, [
                'document_name' => 'Government-issued photo ID',
                'description' => 'Passport, Aadhaar or driving licence.',
                'mandatory_flag' => 1,
                'accepted_file_types' => 'pdf,jpg,jpeg,png',
                'max_size_bytes' => 10485760,
                'single_or_multiple' => 'single',
                'reuse_allowed' => 1,
                'reviewer_instructions' => null,
                'sort_order' => 2,
            ], $now);

            $this->publishVersion($pdo, $versionId, $now);
            $this->setCoursePublishedVersion($pdo, $courseId, $versionId, $now);

            $this->insertBatchIfMissing($pdo, $versionId, 'WP02-DEMO-OBESITY-101-OPEN', [
                'name' => 'January 2027 cohort (open)',
                'starts_at' => $this->daysFromNow(45),
                'ends_at' => $this->daysFromNow(87),
                'applications_open_at' => $this->daysFromNow(-5),
                'applications_close_at' => $this->daysFromNow(30),
                'min_capacity' => 10,
                'max_capacity' => 60,
                'delivery_mode' => 'online',
                'venue_or_online_details' => 'Live sessions via Zoom; recordings available.',
                'timezone' => 'Asia/Kolkata',
                'fee_override' => null,
                'currency' => 'INR',
                'status' => 'open_for_applications',
                'access_expires_at' => null,
            ], $now);

            $this->insertBatchIfMissing($pdo, $versionId, 'WP02-DEMO-OBESITY-101-UPCOMING', [
                'name' => 'April 2027 cohort (upcoming)',
                'starts_at' => $this->daysFromNow(150),
                'ends_at' => $this->daysFromNow(192),
                'applications_open_at' => $this->daysFromNow(60),
                'applications_close_at' => $this->daysFromNow(120),
                'min_capacity' => 10,
                'max_capacity' => 60,
                'delivery_mode' => 'online',
                'venue_or_online_details' => 'Live sessions via Zoom; recordings available.',
                'timezone' => 'Asia/Kolkata',
                'fee_override' => null,
                'currency' => 'INR',
                'status' => 'planned',
                'access_expires_at' => null,
            ], $now);

            $this->insertBatchIfMissing($pdo, $versionId, 'WP02-DEMO-OBESITY-101-CLOSED', [
                'name' => 'October 2026 cohort (closed)',
                'starts_at' => $this->daysFromNow(-90),
                'ends_at' => $this->daysFromNow(-48),
                'applications_open_at' => $this->daysFromNow(-140),
                'applications_close_at' => $this->daysFromNow(-100),
                'min_capacity' => 10,
                'max_capacity' => 60,
                'delivery_mode' => 'online',
                'venue_or_online_details' => 'Live sessions via Zoom; recordings available.',
                'timezone' => 'Asia/Kolkata',
                'fee_override' => null,
                'currency' => 'INR',
                'status' => 'completed',
                'access_expires_at' => $this->daysFromNow(275),
            ], $now);

            $this->insertBatchIfMissing($pdo, $versionId, 'WP02-DEMO-OBESITY-101-FULL', [
                'name' => 'February 2027 cohort (full)',
                'starts_at' => $this->daysFromNow(70),
                'ends_at' => $this->daysFromNow(112),
                'applications_open_at' => $this->daysFromNow(-20),
                'applications_close_at' => $this->daysFromNow(20),
                'min_capacity' => 10,
                'max_capacity' => 40,
                'delivery_mode' => 'online',
                'venue_or_online_details' => 'Live sessions via Zoom; recordings available.',
                'timezone' => 'Asia/Kolkata',
                'fee_override' => null,
                'currency' => 'INR',
                'status' => 'full',
                'access_expires_at' => null,
            ], $now);
        }
    }

    private function seedMetabolicHealthCourse(PDO $pdo, string $now): void
    {
        $courseCode = 'WP02-DEMO-METAB-201';
        $courseId = $this->findCourseId($pdo, $courseCode);

        if ($courseId === null) {
            $courseId = $this->insertCourse($pdo, $courseCode, 'metabolic-health-advanced', 'Advanced Metabolic Health Management', $now);
            $versionId = $this->insertDraftVersion($pdo, $courseId, [
                'version_number' => 1,
                'title' => 'Advanced Metabolic Health Management — Batch 2026',
                'description' => 'An advanced Continuing Medical Education course on the management of metabolic syndrome, type 2 diabetes and cardiometabolic risk.',
                'learning_objectives' => 'Interpret metabolic panels; stratify cardiometabolic risk; individualise pharmacological and lifestyle therapy for complex metabolic disease.',
                'intended_audience' => 'Doctors and allied medical professionals with prior experience managing chronic metabolic conditions.',
                'syllabus_summary' => 'Module 1: Metabolic syndrome pathophysiology. Module 2: Advanced pharmacotherapy. Module 3: Cardiometabolic risk stratification. Module 4: Case-based practicum.',
                'admission_mode' => 'A',
                'delivery_type' => 'blended',
                'duration_text' => '8 weeks, blended online and in-person workshop',
                'validity_period_days' => 365,
                'standard_fee' => '22000.00',
                'gst_rate' => '18.00',
                'currency' => 'INR',
                'certificate_type' => 'Certificate of Completion',
                'faq_json' => null,
            ], $now);

            $this->insertEligibilityRule($pdo, $versionId, [
                'field' => 'profession',
                'operator' => 'in',
                'value' => 'doctor,allied_professional',
                'logic_group' => 'AND',
                'display_label' => 'Must be a registered doctor or allied medical professional.',
                'sort_order' => 1,
            ], $now);

            $this->insertDocumentRequirement($pdo, $versionId, [
                'document_name' => 'Medical council registration certificate',
                'description' => 'A clear scan or photo of your current medical council registration certificate.',
                'mandatory_flag' => 1,
                'accepted_file_types' => 'pdf,jpg,jpeg,png',
                'max_size_bytes' => 10485760,
                'single_or_multiple' => 'single',
                'reuse_allowed' => 1,
                'reviewer_instructions' => null,
                'sort_order' => 1,
            ], $now);

            $this->publishVersion($pdo, $versionId, $now);
            $this->setCoursePublishedVersion($pdo, $courseId, $versionId, $now);

            $this->insertBatchIfMissing($pdo, $versionId, 'WP02-DEMO-METAB-201-OPEN', [
                'name' => 'March 2027 cohort (open)',
                'starts_at' => $this->daysFromNow(60),
                'ends_at' => $this->daysFromNow(116),
                'applications_open_at' => $this->daysFromNow(-3),
                'applications_close_at' => $this->daysFromNow(40),
                'min_capacity' => 8,
                'max_capacity' => 30,
                'delivery_mode' => 'blended',
                'venue_or_online_details' => 'Online modules plus a one-day in-person workshop in Bengaluru.',
                'timezone' => 'Asia/Kolkata',
                'fee_override' => null,
                'currency' => 'INR',
                'status' => 'open_for_applications',
                'access_expires_at' => null,
            ], $now);
        }
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
            ->modify($days . ' days')
            ->format('Y-m-d H:i:s.u');
    }

    private function findCourseId(PDO $pdo, string $courseCode): ?int
    {
        $stmt = $pdo->prepare('SELECT course_id FROM courses WHERE course_code = :course_code');
        $stmt->execute(['course_code' => $courseCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['course_id'];
    }

    private function insertCourse(PDO $pdo, string $courseCode, string $slug, string $masterTitle, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO courses (
                course_code, slug, master_title, status, current_published_version_id, created_at, updated_at
            ) VALUES (
                :course_code, :slug, :master_title, :status, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_code' => $courseCode,
            'slug' => $slug,
            'master_title' => $masterTitle,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertDraftVersion(PDO $pdo, int $courseId, array $fields, string $now): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO course_versions (
                course_id, version_number, title, description, learning_objectives, intended_audience,
                syllabus_summary, admission_mode, delivery_type, duration_text, validity_period_days,
                standard_fee, gst_rate, currency, certificate_type, faq_json, status,
                published_at, locked_at, locked_reason, created_at, updated_at
            ) VALUES (
                :course_id, :version_number, :title, :description, :learning_objectives, :intended_audience,
                :syllabus_summary, :admission_mode, :delivery_type, :duration_text, :validity_period_days,
                :standard_fee, :gst_rate, :currency, :certificate_type, :faq_json, :status,
                NULL, NULL, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_id' => $courseId,
            'version_number' => $fields['version_number'],
            'title' => $fields['title'],
            'description' => $fields['description'],
            'learning_objectives' => $fields['learning_objectives'],
            'intended_audience' => $fields['intended_audience'],
            'syllabus_summary' => $fields['syllabus_summary'],
            'admission_mode' => $fields['admission_mode'],
            'delivery_type' => $fields['delivery_type'],
            'duration_text' => $fields['duration_text'],
            'validity_period_days' => $fields['validity_period_days'],
            'standard_fee' => $fields['standard_fee'],
            'gst_rate' => $fields['gst_rate'],
            'currency' => $fields['currency'],
            'certificate_type' => $fields['certificate_type'],
            'faq_json' => $fields['faq_json'],
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertEligibilityRule(PDO $pdo, int $versionId, array $fields, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO eligibility_rules (
                course_version_id, field, operator, value, logic_group, display_label, sort_order,
                created_at, updated_at
            ) VALUES (
                :course_version_id, :field, :operator, :value, :logic_group, :display_label, :sort_order,
                :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'course_version_id' => $versionId,
            'field' => $fields['field'],
            'operator' => $fields['operator'],
            'value' => $fields['value'],
            'logic_group' => $fields['logic_group'],
            'display_label' => $fields['display_label'],
            'sort_order' => $fields['sort_order'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertDocumentRequirement(PDO $pdo, int $versionId, array $fields, string $now): void
    {
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
            'document_name' => $fields['document_name'],
            'description' => $fields['description'],
            'mandatory_flag' => $fields['mandatory_flag'],
            'accepted_file_types' => $fields['accepted_file_types'],
            'max_size_bytes' => $fields['max_size_bytes'],
            'single_or_multiple' => $fields['single_or_multiple'],
            'reuse_allowed' => $fields['reuse_allowed'],
            'reviewer_instructions' => $fields['reviewer_instructions'],
            'sort_order' => $fields['sort_order'],
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
            'UPDATE courses SET current_published_version_id = :version_id, updated_at = :updated_at
             WHERE course_id = :course_id',
        );
        $stmt->execute([
            'version_id' => $versionId,
            'updated_at' => $now,
            'course_id' => $courseId,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertBatchIfMissing(PDO $pdo, int $versionId, string $batchCode, array $fields, string $now): void
    {
        $existing = $pdo->prepare('SELECT batch_id FROM batches WHERE batch_code = :batch_code');
        $existing->execute(['batch_code' => $batchCode]);
        if ($existing->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

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
            'batch_code' => $batchCode,
            'name' => $fields['name'],
            'starts_at' => $fields['starts_at'],
            'ends_at' => $fields['ends_at'],
            'applications_open_at' => $fields['applications_open_at'],
            'applications_close_at' => $fields['applications_close_at'],
            'min_capacity' => $fields['min_capacity'],
            'max_capacity' => $fields['max_capacity'],
            'delivery_mode' => $fields['delivery_mode'],
            'venue_or_online_details' => $fields['venue_or_online_details'],
            'timezone' => $fields['timezone'],
            'fee_override' => $fields['fee_override'],
            'currency' => $fields['currency'],
            'status' => $fields['status'],
            'access_expires_at' => $fields['access_expires_at'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
