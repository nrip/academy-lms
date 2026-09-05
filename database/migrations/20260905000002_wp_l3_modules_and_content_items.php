<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L3 — Modules and Content Items (Draft CourseVersion curriculum).
 */
final class WpL3ModulesAndContentItems extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE modules (
    module_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_version_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    mandatory_flag TINYINT(1) NOT NULL DEFAULT 1,
    release_rule VARCHAR(32) NOT NULL DEFAULT 'immediate',
    prerequisite_module_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (module_id),
    UNIQUE KEY uq_modules_version_sequence (course_version_id, sequence),
    KEY idx_modules_course_version_id (course_version_id),
    CONSTRAINT fk_modules_course_version_id FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_modules_prerequisite_module_id FOREIGN KEY (prerequisite_module_id)
        REFERENCES modules (module_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_modules_release_rule CHECK (release_rule IN ('immediate', 'sequential')),
    CONSTRAINT chk_modules_mandatory_flag CHECK (mandatory_flag IN (0, 1)),
    CONSTRAINT chk_modules_sequence CHECK (sequence >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE content_items (
    content_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    content_type VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body_text MEDIUMTEXT NULL,
    object_key VARCHAR(512) NULL,
    mandatory_flag TINYINT(1) NOT NULL DEFAULT 1,
    completion_rule VARCHAR(32) NOT NULL DEFAULT 'mark_complete',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (content_id),
    UNIQUE KEY uq_content_items_module_sequence (module_id, sequence),
    KEY idx_content_items_module_id (module_id),
    CONSTRAINT fk_content_items_module_id FOREIGN KEY (module_id)
        REFERENCES modules (module_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_content_items_type CHECK (content_type IN ('text_lesson', 'pdf', 'mcq_assessment')),
    CONSTRAINT chk_content_items_completion_rule CHECK (completion_rule IN ('mark_complete', 'assessment_passed')),
    CONSTRAINT chk_content_items_mandatory_flag CHECK (mandatory_flag IN (0, 1)),
    CONSTRAINT chk_content_items_sequence CHECK (sequence >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_modules_forbid_insert_when_locked
BEFORE INSERT ON modules
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = NEW.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add Module to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_modules_forbid_update_when_locked
BEFORE UPDATE ON modules
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = OLD.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Module belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_modules_forbid_delete_when_locked
BEFORE DELETE ON modules
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM course_versions cv
        WHERE cv.version_id = OLD.course_version_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Module belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_content_items_forbid_insert_when_locked
BEFORE INSERT ON content_items
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM modules m
        INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
        WHERE m.module_id = NEW.module_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add ContentItem to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_content_items_forbid_update_when_locked
BEFORE UPDATE ON content_items
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM modules m
        INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
        WHERE m.module_id = OLD.module_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ContentItem belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER trg_content_items_forbid_delete_when_locked
BEFORE DELETE ON content_items
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM modules m
        INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
        WHERE m.module_id = OLD.module_id AND cv.locked_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ContentItem belongs to a locked CourseVersion';
    END IF;
END
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            ['module.manage', 'Manage modules on unlocked CourseVersions', 1],
            ['content.manage', 'Manage content items on unlocked CourseVersions', 1],
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

        foreach (['course_admin', 'super_admin'] as $roleKey) {
            foreach (['module.manage', 'content.manage'] as $permissionKey) {
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
        foreach (['module.manage', 'content.manage'] as $key) {
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

        $this->execute('DROP TRIGGER IF EXISTS trg_content_items_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_content_items_forbid_update_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_content_items_forbid_insert_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_modules_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_modules_forbid_update_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_modules_forbid_insert_when_locked');
        $this->execute('DROP TABLE IF EXISTS content_items');
        $this->execute('DROP TABLE IF EXISTS modules');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
