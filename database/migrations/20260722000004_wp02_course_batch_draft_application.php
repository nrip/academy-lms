<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-02 — Course, CourseVersion, Batch, EligibilityRule, CourseDocumentRequirement,
 * Application (Draft factory schema), and CourseVersion immutability triggers.
 */
final class Wp02CourseBatchDraftApplication extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE courses (
    course_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_code VARCHAR(64) NOT NULL,
    slug VARCHAR(128) NOT NULL,
    master_title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    current_published_version_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (course_id),
    UNIQUE KEY uq_courses_course_code (course_code),
    UNIQUE KEY uq_courses_slug (slug),
    KEY idx_courses_status (status),
    CONSTRAINT chk_courses_status CHECK (status IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE course_versions (
    version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    learning_objectives TEXT NOT NULL,
    intended_audience VARCHAR(512) NOT NULL,
    syllabus_summary TEXT NOT NULL,
    admission_mode CHAR(1) NOT NULL,
    delivery_type VARCHAR(64) NOT NULL,
    duration_text VARCHAR(128) NOT NULL,
    validity_period_days INT UNSIGNED NULL,
    standard_fee DECIMAL(12,2) NOT NULL,
    gst_rate DECIMAL(5,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    certificate_type VARCHAR(128) NOT NULL,
    faq_json JSON NULL,
    status VARCHAR(32) NOT NULL,
    published_at DATETIME(6) NULL,
    locked_at DATETIME(6) NULL,
    locked_reason VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (version_id),
    UNIQUE KEY uq_course_versions_course_version (course_id, version_number),
    KEY idx_course_versions_status (status),
    KEY idx_course_versions_locked_at (locked_at),
    CONSTRAINT fk_course_versions_course_id FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_course_versions_admission_mode CHECK (admission_mode IN ('A', 'B', 'C')),
    CONSTRAINT chk_course_versions_status CHECK (
        status IN ('draft', 'under_review', 'published', 'enrolment_closed', 'unpublished', 'archived', 'cancelled')
    ),
    CONSTRAINT chk_course_versions_fee CHECK (standard_fee >= 0),
    CONSTRAINT chk_course_versions_gst CHECK (gst_rate >= 0 AND gst_rate <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE courses
    ADD CONSTRAINT fk_courses_current_published_version_id
    FOREIGN KEY (current_published_version_id) REFERENCES course_versions (version_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE eligibility_rules (
    rule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_version_id BIGINT UNSIGNED NOT NULL,
    field VARCHAR(64) NOT NULL,
    operator VARCHAR(32) NOT NULL,
    value VARCHAR(255) NOT NULL,
    logic_group VARCHAR(8) NOT NULL,
    display_label VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (rule_id),
    KEY idx_eligibility_rules_course_version_id (course_version_id),
    CONSTRAINT fk_eligibility_rules_course_version_id FOREIGN KEY (course_version_id) REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_eligibility_rules_logic_group CHECK (logic_group IN ('AND', 'OR'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE course_document_requirements (
    requirement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_version_id BIGINT UNSIGNED NOT NULL,
    document_name VARCHAR(128) NOT NULL,
    description TEXT NOT NULL,
    mandatory_flag TINYINT(1) NOT NULL DEFAULT 1,
    accepted_file_types VARCHAR(255) NOT NULL,
    max_size_bytes INT UNSIGNED NOT NULL,
    single_or_multiple VARCHAR(16) NOT NULL,
    reuse_allowed TINYINT(1) NOT NULL DEFAULT 0,
    reviewer_instructions TEXT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (requirement_id),
    KEY idx_course_document_requirements_version (course_version_id),
    CONSTRAINT fk_course_document_requirements_version FOREIGN KEY (course_version_id) REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_course_document_requirements_multi CHECK (single_or_multiple IN ('single', 'multiple')),
    CONSTRAINT chk_course_document_requirements_max_size CHECK (max_size_bytes > 0 AND max_size_bytes <= 10485760)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE batches (
    batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_version_id BIGINT UNSIGNED NOT NULL,
    batch_code VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    ends_at DATETIME(6) NOT NULL,
    applications_open_at DATETIME(6) NOT NULL,
    applications_close_at DATETIME(6) NOT NULL,
    min_capacity INT UNSIGNED NOT NULL DEFAULT 0,
    max_capacity INT UNSIGNED NOT NULL,
    delivery_mode VARCHAR(64) NOT NULL,
    venue_or_online_details VARCHAR(512) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
    fee_override DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status VARCHAR(32) NOT NULL,
    access_expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (batch_id),
    UNIQUE KEY uq_batches_batch_code (batch_code),
    KEY idx_batches_course_version_status (course_version_id, status),
    KEY idx_batches_applications_window (applications_open_at, applications_close_at),
    CONSTRAINT fk_batches_course_version_id FOREIGN KEY (course_version_id) REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_batches_status CHECK (
        status IN (
            'planned',
            'open_for_applications',
            'open_for_enrolment',
            'full',
            'in_progress',
            'completed',
            'cancelled',
            'archived'
        )
    ),
    CONSTRAINT chk_batches_dates CHECK (
        ends_at >= starts_at
        AND applications_close_at >= applications_open_at
    ),
    CONSTRAINT chk_batches_capacity CHECK (max_capacity >= min_capacity),
    CONSTRAINT chk_batches_fee_override CHECK (fee_override IS NULL OR fee_override >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE applications (
    application_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    course_version_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    submitted_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (application_id),
    UNIQUE KEY uq_applications_user_batch (user_id, batch_id),
    KEY idx_applications_batch_status (batch_id, status, submitted_at),
    KEY idx_applications_user_status (user_id, status),
    KEY idx_applications_course_version_id (course_version_id),
    CONSTRAINT fk_applications_user_id FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_applications_course_version_id FOREIGN KEY (course_version_id) REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_applications_batch_id FOREIGN KEY (batch_id) REFERENCES batches (batch_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_applications_status CHECK (
        status IN (
            'draft',
            'submitted',
            'documents_incomplete',
            'under_review',
            'resubmission_requested',
            'payment_pending',
            'awaiting_verification',
            'admitted',
            'rejected',
            'withdrawn',
            'expired'
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_course_versions_forbid_update_when_locked
BEFORE UPDATE ON course_versions
FOR EACH ROW
BEGIN
    IF OLD.locked_at IS NOT NULL THEN
        IF NEW.course_id <> OLD.course_id
            OR NEW.version_number <> OLD.version_number
            OR NEW.title <> OLD.title
            OR NEW.description <> OLD.description
            OR NEW.learning_objectives <> OLD.learning_objectives
            OR NEW.intended_audience <> OLD.intended_audience
            OR NEW.syllabus_summary <> OLD.syllabus_summary
            OR NEW.admission_mode <> OLD.admission_mode
            OR NEW.delivery_type <> OLD.delivery_type
            OR NEW.duration_text <> OLD.duration_text
            OR NOT (NEW.validity_period_days <=> OLD.validity_period_days)
            OR NEW.standard_fee <> OLD.standard_fee
            OR NEW.gst_rate <> OLD.gst_rate
            OR NEW.currency <> OLD.currency
            OR NEW.certificate_type <> OLD.certificate_type
            OR NOT (NEW.faq_json <=> OLD.faq_json)
            OR (NEW.status <> OLD.status AND NEW.status NOT IN ('enrolment_closed', 'unpublished', 'archived', 'cancelled'))
        THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked CourseVersion configuration is immutable';
        END IF;
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_course_versions_forbid_delete_when_locked
BEFORE DELETE ON course_versions
FOR EACH ROW
BEGIN
    IF OLD.locked_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked CourseVersion cannot be deleted';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_eligibility_rules_forbid_mutate_when_locked
BEFORE UPDATE ON eligibility_rules
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = OLD.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EligibilityRule belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_eligibility_rules_forbid_delete_when_locked
BEFORE DELETE ON eligibility_rules
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = OLD.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EligibilityRule belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_course_document_requirements_forbid_mutate_when_locked
BEFORE UPDATE ON course_document_requirements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = OLD.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CourseDocumentRequirement belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_course_document_requirements_forbid_delete_when_locked
BEFORE DELETE ON course_document_requirements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = OLD.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CourseDocumentRequirement belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_eligibility_rules_forbid_insert_when_locked
BEFORE INSERT ON eligibility_rules
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = NEW.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add EligibilityRule to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_course_document_requirements_forbid_insert_when_locked
BEFORE INSERT ON course_document_requirements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = NEW.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add CourseDocumentRequirement to a locked CourseVersion';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_course_document_requirements_forbid_insert_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_eligibility_rules_forbid_insert_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_course_document_requirements_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_course_document_requirements_forbid_mutate_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_eligibility_rules_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_eligibility_rules_forbid_mutate_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_course_versions_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_course_versions_forbid_update_when_locked');
        $this->execute('DROP TABLE IF EXISTS applications');
        $this->execute('DROP TABLE IF EXISTS batches');
        $this->execute('DROP TABLE IF EXISTS course_document_requirements');
        $this->execute('DROP TABLE IF EXISTS eligibility_rules');
        $this->execute('ALTER TABLE courses DROP FOREIGN KEY fk_courses_current_published_version_id');
        $this->execute('DROP TABLE IF EXISTS course_versions');
        $this->execute('DROP TABLE IF EXISTS courses');
    }
}
