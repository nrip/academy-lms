<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L8 — Completion certificates and public verification.
 */
final class WpL8CompletionCertificates extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE certificates (
    certificate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    course_version_id BIGINT UNSIGNED NOT NULL,
    certificate_type VARCHAR(32) NOT NULL,
    certificate_number VARCHAR(64) NOT NULL,
    verification_hash VARCHAR(64) NOT NULL,
    learner_name_snapshot VARCHAR(200) NOT NULL,
    course_title_snapshot VARCHAR(255) NOT NULL,
    version_title_snapshot VARCHAR(255) NOT NULL,
    certificate_label VARCHAR(128) NOT NULL,
    status VARCHAR(16) NOT NULL,
    current_marker TINYINT UNSIGNED NULL,
    issued_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (certificate_id),
    UNIQUE KEY uq_certificates_number (certificate_number),
    UNIQUE KEY uq_certificates_verification_hash (verification_hash),
    UNIQUE KEY uq_certificates_current (enrolment_id, certificate_type, current_marker),
    KEY idx_certificates_enrolment (enrolment_id, status),
    CONSTRAINT fk_certificates_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_certificates_course_version FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_certificates_type CHECK (certificate_type IN ('completion')),
    CONSTRAINT chk_certificates_status CHECK (status IN ('active', 'revoked')),
    CONSTRAINT chk_certificates_current_marker CHECK (
        current_marker IS NULL OR current_marker = 1
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE certificate_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_certificate_events_certificate (certificate_id, created_at),
    CONSTRAINT fk_certificate_events_certificate FOREIGN KEY (certificate_id)
        REFERENCES certificates (certificate_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_certificate_events_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_certificate_events_type CHECK (event_type IN ('issued', 'revoked', 'reissued'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $this->execute(sprintf(
            "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
             VALUES (%s, %s, 0, %s)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
            $this->quote('certificate.view_own'),
            $this->quote('View own completion certificates'),
            $this->quote($now),
        ));

        foreach (['applicant', 'super_admin'] as $roleKey) {
            $this->execute(sprintf(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 INNER JOIN permissions p ON p.permission_key = %s
                 WHERE r.role_key = %s",
                $this->quote($now),
                $this->quote('certificate.view_own'),
                $this->quote($roleKey),
            ));
        }
    }

    public function down(): void
    {
        $this->execute(sprintf(
            "DELETE rp FROM role_permissions rp
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE p.permission_key = %s",
            $this->quote('certificate.view_own'),
        ));
        $this->execute(sprintf(
            'DELETE FROM permissions WHERE permission_key = %s',
            $this->quote('certificate.view_own'),
        ));
        $this->execute('DROP TABLE IF EXISTS certificate_events');
        $this->execute('DROP TABLE IF EXISTS certificates');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
