<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-04 — Reviewer Verification: object scope, queue claims, verification audit,
 * document review fields, reviewer/learner permissions.
 */
final class Wp04ReviewerVerification extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE reviewer_scope_assignments (
    scope_assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    scope_type VARCHAR(32) NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    course_version_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    include_future_versions TINYINT UNSIGNED NOT NULL DEFAULT 0,
    effective_from DATETIME(6) NOT NULL,
    effective_to DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    revoked_reason VARCHAR(255) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    revoked_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (scope_assignment_id),
    KEY idx_reviewer_scope_reviewer (reviewer_user_id, revoked_at, effective_from),
    KEY idx_reviewer_scope_course (course_id),
    KEY idx_reviewer_scope_version (course_version_id),
    KEY idx_reviewer_scope_batch (batch_id),
    CONSTRAINT fk_reviewer_scope_reviewer FOREIGN KEY (reviewer_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reviewer_scope_course FOREIGN KEY (course_id)
        REFERENCES courses (course_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reviewer_scope_version FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reviewer_scope_batch FOREIGN KEY (batch_id)
        REFERENCES batches (batch_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reviewer_scope_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reviewer_scope_revoker FOREIGN KEY (revoked_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_reviewer_scope_type CHECK (scope_type IN ('course', 'course_version', 'batch')),
    CONSTRAINT chk_reviewer_scope_target CHECK (
        (scope_type = 'course' AND course_id IS NOT NULL AND course_version_id IS NULL AND batch_id IS NULL)
        OR (scope_type = 'course_version' AND course_version_id IS NOT NULL AND course_id IS NULL AND batch_id IS NULL)
        OR (scope_type = 'batch' AND batch_id IS NOT NULL AND course_id IS NULL AND course_version_id IS NULL)
    ),
    CONSTRAINT chk_reviewer_scope_future CHECK (include_future_versions IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE application_review_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    assignment_status VARCHAR(32) NOT NULL,
    claimed_at DATETIME(6) NOT NULL,
    released_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    active_marker TINYINT UNSIGNED NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (assignment_id),
    UNIQUE KEY uq_application_review_assignments_active (application_id, active_marker),
    KEY idx_application_review_assignments_reviewer (reviewer_user_id, assignment_status),
    CONSTRAINT fk_application_review_assignments_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_application_review_assignments_reviewer FOREIGN KEY (reviewer_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_application_review_assignments_status CHECK (
        assignment_status IN ('active', 'released', 'completed')
    ),
    CONSTRAINT chk_application_review_assignments_active_marker CHECK (
        active_marker IS NULL OR active_marker = 1
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE verification_audit_log (
    verification_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    document_submission_id BIGINT UNSIGNED NULL,
    requirement_id BIGINT UNSIGNED NULL,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(64) NOT NULL,
    status_before VARCHAR(64) NULL,
    status_after VARCHAR(64) NULL,
    reason_code VARCHAR(64) NULL,
    learner_visible_message VARCHAR(500) NULL,
    internal_note VARCHAR(1000) NULL,
    state_version INT UNSIGNED NULL,
    row_version INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (verification_audit_id),
    KEY idx_verification_audit_application (application_id, created_at),
    KEY idx_verification_audit_document (document_submission_id),
    CONSTRAINT fk_verification_audit_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_verification_audit_document FOREIGN KEY (document_submission_id)
        REFERENCES document_submissions (document_submission_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_verification_audit_requirement FOREIGN KEY (requirement_id)
        REFERENCES course_document_requirements (requirement_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_verification_audit_reviewer FOREIGN KEY (reviewer_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_verification_audit_log_forbid_update
BEFORE UPDATE ON verification_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verification_audit_log is append-only'
SQL);
        $this->execute(<<<'SQL'
CREATE TRIGGER trg_verification_audit_log_forbid_delete
BEFORE DELETE ON verification_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'verification_audit_log is append-only'
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE document_submissions
    ADD COLUMN learner_visible_message VARCHAR(500) NULL AFTER rejection_reason_code,
    ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER learner_visible_message,
    ADD COLUMN reviewed_at DATETIME(6) NULL AFTER reviewed_by_user_id
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE document_submissions
    ADD CONSTRAINT fk_document_submissions_reviewer FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL);

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $permissions = [
            ['reviewer.application.claim', 'Claim or release application for review', 1],
            ['reviewer.application.approve', 'Approve application to payment pending', 1],
            ['reviewer.application.reject', 'Reject application', 1],
            ['application.resubmit_corrections_own', 'Resubmit corrected application documents', 0],
        ];

        foreach ($permissions as [$key, $description, $sensitive]) {
            $this->execute(sprintf(
                "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
                 VALUES (%s, %s, %d, %s)",
                $this->quote($key),
                $this->quote($description),
                $sensitive,
                $this->quote($now),
            ));
        }

        $reviewerKeys = [
            'reviewer.application.claim',
            'reviewer.application.approve',
            'reviewer.application.reject',
        ];
        foreach (['credential_reviewer', 'super_admin'] as $roleKey) {
            foreach ($reviewerKeys as $key) {
                $this->execute(sprintf(
                    "INSERT INTO role_permissions (role_id, permission_id, created_at)
                     SELECT r.role_id, p.permission_id, %s
                     FROM roles r
                     CROSS JOIN permissions p
                     WHERE r.role_key = %s AND p.permission_key = %s",
                    $this->quote($now),
                    $this->quote($roleKey),
                    $this->quote($key),
                ));
            }
        }

        $this->execute(sprintf(
            "INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.role_id, p.permission_id, %s
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_key = 'applicant' AND p.permission_key = 'application.resubmit_corrections_own'",
            $this->quote($now),
        ));
    }

    public function down(): void
    {
        $keys = [
            'reviewer.application.claim',
            'reviewer.application.approve',
            'reviewer.application.reject',
            'application.resubmit_corrections_own',
        ];
        foreach ($keys as $key) {
            $this->execute(sprintf(
                "DELETE rp FROM role_permissions rp
                 INNER JOIN permissions p ON p.permission_id = rp.permission_id
                 WHERE p.permission_key = %s",
                $this->quote($key),
            ));
            $this->execute(sprintf(
                'DELETE FROM permissions WHERE permission_key = %s',
                $this->quote($key),
            ));
        }

        $this->execute('ALTER TABLE document_submissions DROP FOREIGN KEY fk_document_submissions_reviewer');
        $this->execute(<<<'SQL'
ALTER TABLE document_submissions
    DROP COLUMN reviewed_at,
    DROP COLUMN reviewed_by_user_id,
    DROP COLUMN learner_visible_message
SQL);

        $this->execute('DROP TRIGGER IF EXISTS trg_verification_audit_log_forbid_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_verification_audit_log_forbid_delete');
        $this->execute('DROP TABLE IF EXISTS verification_audit_log');
        $this->execute('DROP TABLE IF EXISTS application_review_assignments');
        $this->execute('DROP TABLE IF EXISTS reviewer_scope_assignments');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
