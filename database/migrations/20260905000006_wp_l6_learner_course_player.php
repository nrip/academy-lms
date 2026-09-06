<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L6 — Learner Course Player + ContentProgress.
 */
final class WpL6LearnerCoursePlayer extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE content_progress (
    progress_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    completion_status VARCHAR(32) NOT NULL,
    resume_position VARCHAR(64) NULL,
    watch_percentage DECIMAL(5,2) NULL,
    first_accessed_at DATETIME(6) NULL,
    last_accessed_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    manual_override_flag TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completion_source VARCHAR(32) NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (progress_id),
    UNIQUE KEY uq_content_progress_enrolment_content (enrolment_id, content_id),
    KEY idx_content_progress_enrolment (enrolment_id, completion_status),
    CONSTRAINT fk_content_progress_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_content_progress_content FOREIGN KEY (content_id)
        REFERENCES content_items (content_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_content_progress_status CHECK (completion_status IN (
        'not_started', 'in_progress', 'completed'
    )),
    CONSTRAINT chk_content_progress_source CHECK (
        completion_source IS NULL OR completion_source IN ('learner', 'assessment', 'system')
    ),
    CONSTRAINT chk_content_progress_override CHECK (manual_override_flag IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $this->execute(sprintf(
            "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
             VALUES (%s, %s, 0, %s)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
            $this->quote('learning.content.access'),
            $this->quote('Access own Active Enrolment course player and content progress'),
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
                $this->quote('learning.content.access'),
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
            $this->quote('learning.content.access'),
        ));
        $this->execute(sprintf(
            'DELETE FROM permissions WHERE permission_key = %s',
            $this->quote('learning.content.access'),
        ));
        $this->execute('DROP TABLE IF EXISTS content_progress');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
