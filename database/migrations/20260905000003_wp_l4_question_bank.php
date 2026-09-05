<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L4 — Course-scoped question bank (MCQ authoring only).
 */
final class WpL4QuestionBank extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE question_banks (
    bank_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bank_id),
    UNIQUE KEY uq_question_banks_course_id (course_id),
    CONSTRAINT fk_question_banks_course_id FOREIGN KEY (course_id)
        REFERENCES courses (course_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE questions (
    question_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_id BIGINT UNSIGNED NOT NULL,
    question_type VARCHAR(32) NOT NULL,
    stem TEXT NOT NULL,
    marks DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    explanation TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (question_id),
    KEY idx_questions_bank_id (bank_id),
    KEY idx_questions_bank_status (bank_id, status),
    CONSTRAINT fk_questions_bank_id FOREIGN KEY (bank_id)
        REFERENCES question_banks (bank_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_questions_type CHECK (question_type IN ('mcq_single')),
    CONSTRAINT chk_questions_status CHECK (status IN ('active', 'inactive')),
    CONSTRAINT chk_questions_version CHECK (version >= 1),
    CONSTRAINT chk_questions_marks CHECK (marks > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE question_options (
    option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    option_text VARCHAR(1000) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (option_id),
    UNIQUE KEY uq_question_options_question_sequence (question_id, sequence),
    KEY idx_question_options_question_id (question_id),
    CONSTRAINT fk_question_options_question_id FOREIGN KEY (question_id)
        REFERENCES questions (question_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_question_options_is_correct CHECK (is_correct IN (0, 1)),
    CONSTRAINT chk_question_options_sequence CHECK (sequence >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $this->execute(sprintf(
            "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
             VALUES (%s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
            $this->quote('question_bank.manage'),
            $this->quote('Manage course question bank (MCQ authoring)'),
            $this->quote($now),
        ));

        foreach (['course_admin', 'super_admin'] as $roleKey) {
            $this->execute(sprintf(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                 SELECT r.role_id, p.permission_id, %s
                 FROM roles r
                 INNER JOIN permissions p ON p.permission_key = %s
                 WHERE r.role_key = %s",
                $this->quote($now),
                $this->quote('question_bank.manage'),
                $this->quote($roleKey),
            ));
        }

        // Backfill banks for existing courses (idempotent for demo/UAT seeds).
        $this->execute(sprintf(
            "INSERT INTO question_banks (course_id, title, created_at, updated_at)
             SELECT c.course_id, CONCAT(c.master_title, ' — Question bank'), %s, %s
             FROM courses c
             LEFT JOIN question_banks qb ON qb.course_id = c.course_id
             WHERE qb.bank_id IS NULL",
            $this->quote($now),
            $this->quote($now),
        ));
    }

    public function down(): void
    {
        $this->execute(sprintf(
            "DELETE rp FROM role_permissions rp
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE p.permission_key = %s",
            $this->quote('question_bank.manage'),
        ));
        $this->execute(sprintf(
            'DELETE FROM permissions WHERE permission_key = %s',
            $this->quote('question_bank.manage'),
        ));

        $this->execute('DROP TABLE IF EXISTS question_options');
        $this->execute('DROP TABLE IF EXISTS questions');
        $this->execute('DROP TABLE IF EXISTS question_banks');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
