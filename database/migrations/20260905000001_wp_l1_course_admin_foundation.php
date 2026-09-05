<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L1 — Course Admin foundation: role, permissions, course-admin scope assignments.
 *
 * Does not add curriculum/player/assessment tables (later WPs).
 */
final class WpL1CourseAdminFoundation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE course_admin_scope_assignments (
    scope_assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    scope_type VARCHAR(32) NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    course_version_id BIGINT UNSIGNED NULL,
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
    KEY idx_course_admin_scope_admin (admin_user_id, revoked_at, effective_from),
    KEY idx_course_admin_scope_course (course_id),
    KEY idx_course_admin_scope_version (course_version_id),
    CONSTRAINT fk_course_admin_scope_admin FOREIGN KEY (admin_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_course_admin_scope_course FOREIGN KEY (course_id)
        REFERENCES courses (course_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_course_admin_scope_version FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_course_admin_scope_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_course_admin_scope_revoker FOREIGN KEY (revoked_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_course_admin_scope_type CHECK (scope_type IN ('course', 'course_version')),
    CONSTRAINT chk_course_admin_scope_target CHECK (
        (scope_type = 'course' AND course_id IS NOT NULL AND course_version_id IS NULL)
        OR (scope_type = 'course_version' AND course_version_id IS NOT NULL AND course_id IS NULL)
    ),
    CONSTRAINT chk_course_admin_scope_future CHECK (include_future_versions IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');

        $this->execute(sprintf(
            "INSERT INTO roles (role_key, name, is_privileged, created_at, updated_at)
             VALUES (%s, %s, 1, %s, %s)
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_privileged = VALUES(is_privileged), updated_at = VALUES(updated_at)",
            $this->quote('course_admin'),
            $this->quote('Course Administrator'),
            $this->quote($now),
            $this->quote($now),
        ));

        $permissions = [
            ['course.create', 'Create course identity and draft version', 1],
            ['course.view_assigned', 'View courses in Course Admin scope', 1],
            ['course.version.edit', 'Edit unlocked draft CourseVersions in scope', 1],
            ['course.admin.scope.assign', 'Assign Course Admin object scope', 1],
            ['course.admin.scope.revoke', 'Revoke Course Admin object scope', 1],
        ];

        foreach ($permissions as [$key, $description, $sensitive]) {
            $this->execute(sprintf(
                "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
                 VALUES (%s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
                $this->quote($key),
                $this->quote($description),
                $sensitive,
                $this->quote($now),
            ));
        }

        $identity = [
            'identity.session.view_own',
            'identity.session.revoke_own',
            'identity.password.change_own',
            'profile.personal.view_own',
            'profile.personal.edit_own',
            'profile.professional.view_own',
            'profile.professional.edit_own',
            'mfa.totp.enrol',
            'mfa.totp.verify',
            'mfa.recovery.use',
        ];
        $courseAdminKeys = array_merge($identity, [
            'course.create',
            'course.view_assigned',
            'course.version.edit',
        ]);

        foreach ($courseAdminKeys as $permissionKey) {
            $this->execute(sprintf(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 INNER JOIN permissions p ON p.permission_key = %s
                 WHERE r.role_key = 'course_admin'",
                $this->quote($now),
                $this->quote($permissionKey),
            ));
        }

        $superKeys = [
            'course.create',
            'course.view_assigned',
            'course.version.edit',
            'course.admin.scope.assign',
            'course.admin.scope.revoke',
        ];
        foreach ($superKeys as $permissionKey) {
            $this->execute(sprintf(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 INNER JOIN permissions p ON p.permission_key = %s
                 WHERE r.role_key = 'super_admin'",
                $this->quote($now),
                $this->quote($permissionKey),
            ));
        }
    }

    public function down(): void
    {
        $keys = [
            'course.create',
            'course.view_assigned',
            'course.version.edit',
            'course.admin.scope.assign',
            'course.admin.scope.revoke',
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

        $this->execute("DELETE FROM roles WHERE role_key = 'course_admin'");
        $this->execute('DROP TABLE IF EXISTS course_admin_scope_assignments');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
