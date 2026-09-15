<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RC2 Slice 1 — Learning Q&A (LX-QA-1…LX-QA-4).
 */
final class Rc2LearningQa extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE learning_questions (
    question_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    course_version_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    asked_by_user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(16) NOT NULL,
    asked_at DATETIME(6) NOT NULL,
    first_responded_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL,
    closed_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (question_id),
    KEY idx_learning_questions_course_status (course_id, status, asked_at),
    KEY idx_learning_questions_enrolment_content (enrolment_id, content_id, asked_at),
    KEY idx_learning_questions_asker (asked_by_user_id, asked_at),
    CONSTRAINT fk_learning_questions_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_content FOREIGN KEY (content_id)
        REFERENCES content_items (content_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_course FOREIGN KEY (course_id)
        REFERENCES courses (course_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_version FOREIGN KEY (course_version_id)
        REFERENCES course_versions (version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_batch FOREIGN KEY (batch_id)
        REFERENCES batches (batch_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_module FOREIGN KEY (module_id)
        REFERENCES modules (module_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_asker FOREIGN KEY (asked_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_questions_closer FOREIGN KEY (closed_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_learning_questions_status CHECK (status IN ('open', 'answered', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE learning_question_responses (
    response_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    responded_by_user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    responded_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (response_id),
    KEY idx_learning_question_responses_question (question_id, responded_at),
    CONSTRAINT fk_learning_question_responses_question FOREIGN KEY (question_id)
        REFERENCES learning_questions (question_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_learning_question_responses_user FOREIGN KEY (responded_by_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $permissions = [
            'learning.question.create_own' => 'Ask a private lesson question on an own Active enrolment',
            'learning.question.view_own' => 'View own lesson questions and faculty responses',
            'learning.question.respond' => 'Post a faculty response to a course-scoped lesson question',
            'learning.question.view_scoped' => 'View lesson questions for courses in course-admin scope',
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

        foreach (['learning.question.create_own', 'learning.question.view_own'] as $key) {
            foreach (['applicant', 'super_admin'] as $roleKey) {
                $this->grant($key, $roleKey, $now);
            }
        }
        foreach (['learning.question.respond', 'learning.question.view_scoped'] as $key) {
            foreach (['course_admin', 'super_admin'] as $roleKey) {
                $this->grant($key, $roleKey, $now);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'learning.question.create_own',
            'learning.question.view_own',
            'learning.question.respond',
            'learning.question.view_scoped',
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

        $this->execute('DROP TABLE IF EXISTS learning_question_responses');
        $this->execute('DROP TABLE IF EXISTS learning_questions');
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
