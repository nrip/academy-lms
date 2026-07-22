<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-03 — Application submission fields, document_submissions, upload authorizations,
 * learner document/application permissions.
 */
final class Wp03ApplicationDocumentsSubmit extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE applications
    ADD COLUMN application_number VARCHAR(32) NULL AFTER application_id,
    ADD COLUMN state_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN declaration_accepted_version VARCHAR(32) NULL AFTER submitted_at,
    ADD COLUMN declaration_accepted_at DATETIME(6) NULL AFTER declaration_accepted_version
SQL);

        $this->execute(<<<'SQL'
UPDATE applications
SET application_number = CONCAT('APP-', LPAD(HEX(application_id), 12, '0'))
WHERE application_number IS NULL
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE applications
    MODIFY COLUMN application_number VARCHAR(32) NOT NULL,
    ADD UNIQUE KEY uq_applications_application_number (application_number)
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE document_submissions (
    document_submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    object_key VARCHAR(512) NOT NULL,
    display_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    status VARCHAR(64) NOT NULL,
    scan_status VARCHAR(32) NOT NULL,
    rejection_reason_code VARCHAR(64) NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME(6) NOT NULL,
    superseded_at DATETIME(6) NULL,
    current_marker TINYINT UNSIGNED NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    scan_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    scan_queued_at DATETIME(6) NULL,
    scan_completed_at DATETIME(6) NULL,
    scan_lease_owner VARCHAR(128) NULL,
    scan_lease_token CHAR(36) NULL,
    scan_lease_expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (document_submission_id),
    UNIQUE KEY uq_document_submissions_current (application_id, requirement_id, current_marker),
    UNIQUE KEY uq_document_submissions_object_key (object_key),
    KEY idx_document_submissions_application (application_id),
    KEY idx_document_submissions_requirement (requirement_id),
    KEY idx_document_submissions_scan (scan_status, scan_queued_at),
    CONSTRAINT fk_document_submissions_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_document_submissions_requirement FOREIGN KEY (requirement_id)
        REFERENCES course_document_requirements (requirement_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_document_submissions_uploader FOREIGN KEY (uploaded_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_document_submissions_status CHECK (
        status IN (
            'uploaded',
            'under_review',
            'approved',
            'rejected',
            'resubmission_requested',
            'expired',
            'superseded',
            'failed_security_scan'
        )
    ),
    CONSTRAINT chk_document_submissions_scan_status CHECK (
        scan_status IN ('not_applicable', 'pending', 'clean', 'failed')
    ),
    CONSTRAINT chk_document_submissions_current_marker CHECK (
        current_marker IS NULL OR current_marker = 1
    ),
    CONSTRAINT chk_document_submissions_size CHECK (size_bytes > 0 AND size_bytes <= 10485760)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE document_upload_authorizations (
    authorization_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    object_key VARCHAR(512) NOT NULL,
    display_filename VARCHAR(255) NOT NULL,
    declared_mime_type VARCHAR(128) NOT NULL,
    max_size_bytes INT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (authorization_id),
    UNIQUE KEY uq_document_upload_authorizations_object_key (object_key),
    KEY idx_document_upload_authorizations_app_req (application_id, requirement_id),
    KEY idx_document_upload_authorizations_expires (expires_at),
    CONSTRAINT fk_document_upload_authorizations_application FOREIGN KEY (application_id)
        REFERENCES applications (application_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_document_upload_authorizations_requirement FOREIGN KEY (requirement_id)
        REFERENCES course_document_requirements (requirement_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_document_upload_authorizations_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $permissions = [
            ['application.edit_own', 'Edit own draft application', 0],
            ['application.submit_own', 'Submit own application', 0],
            ['document.upload_own', 'Upload own application documents', 0],
            ['document.view_own', 'View own application documents', 0],
            ['document.replace_own', 'Replace own application documents', 0],
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

        $applicantKeys = [
            'application.edit_own',
            'application.submit_own',
            'document.upload_own',
            'document.view_own',
            'document.replace_own',
        ];
        foreach ($applicantKeys as $key) {
            $this->execute(sprintf(
                "INSERT INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 CROSS JOIN permissions p
                 WHERE r.role_key = 'applicant' AND p.permission_key = %s",
                $this->quote($now),
                $this->quote($key),
            ));
        }

        foreach (['super_admin'] as $roleKey) {
            foreach ($applicantKeys as $key) {
                $this->execute(sprintf(
                    "INSERT INTO role_permissions (role_id, permission_id, created_at)
                     SELECT r.role_id, p.permission_id, %s
                     FROM roles r
                     CROSS JOIN permissions p
                     WHERE r.role_key = %s AND p.permission_key = %s
                     AND NOT EXISTS (
                         SELECT 1 FROM role_permissions rp
                         WHERE rp.role_id = r.role_id AND rp.permission_id = p.permission_id
                     )",
                    $this->quote($now),
                    $this->quote($roleKey),
                    $this->quote($key),
                ));
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'application.edit_own',
            'application.submit_own',
            'document.upload_own',
            'document.view_own',
            'document.replace_own',
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

        $this->execute('DROP TABLE IF EXISTS document_upload_authorizations');
        $this->execute('DROP TABLE IF EXISTS document_submissions');

        $this->execute(<<<'SQL'
ALTER TABLE applications
    DROP INDEX uq_applications_application_number,
    DROP COLUMN application_number,
    DROP COLUMN state_version,
    DROP COLUMN declaration_accepted_version,
    DROP COLUMN declaration_accepted_at
SQL);
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
