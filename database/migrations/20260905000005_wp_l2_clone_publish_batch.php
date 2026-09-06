<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L2 — Clone + Publish + Batch authoring for CourseVersions.
 */
final class WpL2ClonePublishBatch extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE course_versions
    ADD COLUMN cloned_from_version_id BIGINT UNSIGNED NULL AFTER locked_reason,
    ADD KEY idx_course_versions_cloned_from (cloned_from_version_id),
    ADD CONSTRAINT fk_course_versions_cloned_from FOREIGN KEY (cloned_from_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE course_version_status_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (history_id),
    KEY idx_cv_status_history_version (version_id, created_at),
    CONSTRAINT fk_cv_status_history_version FOREIGN KEY (version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cv_status_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_cv_status_history_to CHECK (to_status IN (
        'draft', 'under_review', 'published', 'enrolment_closed', 'unpublished', 'archived', 'cancelled'
    )),
    CONSTRAINT chk_cv_status_history_from CHECK (
        from_status IS NULL OR from_status IN (
            'draft', 'under_review', 'published', 'enrolment_closed', 'unpublished', 'archived', 'cancelled'
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            ['course.version.clone', 'Clone a CourseVersion to a new Draft'],
            ['course.version.publish', 'Publish a Draft CourseVersion'],
            ['batch.create', 'Create batches on published CourseVersions'],
        ];
        foreach ($permissions as [$key, $description]) {
            $this->execute(sprintf(
                "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
                 VALUES (%s, %s, 1, %s)
                 ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
                $this->quote($key),
                $this->quote($description),
                $this->quote($now),
            ));
        }

        foreach (['course_admin', 'super_admin'] as $roleKey) {
            foreach (['course.version.clone', 'course.version.publish', 'batch.create'] as $permissionKey) {
                $this->execute(sprintf(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                     SELECT r.role_id, p.permission_id, %s
                     FROM roles r
                     INNER JOIN permissions p ON p.permission_key = %s
                     WHERE r.role_key = %s",
                    $this->quote($now),
                    $this->quote($permissionKey),
                    $this->quote($roleKey),
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (['course.version.clone', 'course.version.publish', 'batch.create'] as $key) {
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

        $this->execute('DROP TABLE IF EXISTS course_version_status_history');
        $this->execute('ALTER TABLE course_versions DROP FOREIGN KEY fk_course_versions_cloned_from');
        $this->execute('ALTER TABLE course_versions DROP INDEX idx_course_versions_cloned_from');
        $this->execute('ALTER TABLE course_versions DROP COLUMN cloned_from_version_id');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
