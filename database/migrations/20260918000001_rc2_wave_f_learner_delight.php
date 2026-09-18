<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RC2 Wave F — Learner bookmarks, private notes, and simple goals (PX-NOTES-1, PX-GOALS-1).
 */
final class Rc2WaveFLearnerDelight extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE learner_lesson_bookmarks (
    bookmark_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bookmark_id),
    UNIQUE KEY uq_learner_lesson_bookmarks_enrolment_content (enrolment_id, content_id),
    KEY idx_learner_lesson_bookmarks_user (user_id, created_at),
    CONSTRAINT fk_learner_lesson_bookmarks_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learner_lesson_bookmarks_content FOREIGN KEY (content_id)
        REFERENCES content_items (content_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learner_lesson_bookmarks_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE learner_lesson_notes (
    note_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (note_id),
    UNIQUE KEY uq_learner_lesson_notes_enrolment_content (enrolment_id, content_id),
    KEY idx_learner_lesson_notes_user (user_id, updated_at),
    CONSTRAINT fk_learner_lesson_notes_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learner_lesson_notes_content FOREIGN KEY (content_id)
        REFERENCES content_items (content_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learner_lesson_notes_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE learner_enrolment_goals (
    goal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    target_date DATE NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (goal_id),
    UNIQUE KEY uq_learner_enrolment_goals_enrolment (enrolment_id),
    KEY idx_learner_enrolment_goals_user (user_id, target_date),
    CONSTRAINT fk_learner_enrolment_goals_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learner_enrolment_goals_user FOREIGN KEY (user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            'learning.bookmark.manage_own' => 'Save and remove private lesson bookmarks on an own enrolment',
            'learning.note.manage_own' => 'Create and update private lesson notes on an own enrolment',
            'learning.goal.manage_own' => 'Set a private target completion date on an own enrolment',
        ];
        foreach ($permissions as $key => $description) {
            $this->execute(sprintf(
                "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
                 VALUES (%s, %s, 0, %s)
                 ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
                $this->quote($key),
                $this->quote($description),
                $this->quote($now),
            ));
        }

        foreach (array_keys($permissions) as $key) {
            foreach (['applicant', 'super_admin'] as $roleKey) {
                $this->grant($key, $roleKey, $now);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'learning.bookmark.manage_own',
            'learning.note.manage_own',
            'learning.goal.manage_own',
        ] as $key) {
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

        $this->execute('DROP TABLE IF EXISTS learner_enrolment_goals');
        $this->execute('DROP TABLE IF EXISTS learner_lesson_notes');
        $this->execute('DROP TABLE IF EXISTS learner_lesson_bookmarks');
    }

    private function grant(string $permissionKey, string $roleKey, string $now): void
    {
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

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
