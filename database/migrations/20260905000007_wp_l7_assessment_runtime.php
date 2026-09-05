<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L7 — Assessment attempts, snapshots, responses, and scoring.
 */
final class WpL7AssessmentRuntime extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE assessment_attempts (
    attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    enrolment_id BIGINT UNSIGNED NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    score_percent DECIMAL(5,2) NULL,
    marks_awarded DECIMAL(10,2) NULL,
    marks_available DECIMAL(10,2) NULL,
    passed_flag TINYINT UNSIGNED NULL,
    deadline_at DATETIME(6) NULL,
    started_at DATETIME(6) NOT NULL,
    submitted_at DATETIME(6) NULL,
    in_progress_marker TINYINT UNSIGNED NULL,
    pass_threshold_percent DECIMAL(5,2) NOT NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (attempt_id),
    UNIQUE KEY uq_assessment_attempts_number (assessment_id, enrolment_id, attempt_number),
    UNIQUE KEY uq_assessment_attempts_in_progress (assessment_id, enrolment_id, in_progress_marker),
    KEY idx_assessment_attempts_enrolment (enrolment_id, status),
    CONSTRAINT fk_assessment_attempts_assessment FOREIGN KEY (assessment_id)
        REFERENCES assessments (assessment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assessment_attempts_enrolment FOREIGN KEY (enrolment_id)
        REFERENCES enrolments (enrolment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assessment_attempts_content FOREIGN KEY (content_id)
        REFERENCES content_items (content_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_assessment_attempts_status CHECK (status IN ('in_progress', 'submitted', 'timed_out')),
    CONSTRAINT chk_assessment_attempts_number CHECK (attempt_number >= 1),
    CONSTRAINT chk_assessment_attempts_in_progress CHECK (
        in_progress_marker IS NULL OR in_progress_marker = 1
    ),
    CONSTRAINT chk_assessment_attempts_passed CHECK (
        passed_flag IS NULL OR passed_flag IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE assessment_attempt_questions (
    attempt_question_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    question_version INT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    stem TEXT NOT NULL,
    marks DECIMAL(8,2) NOT NULL,
    options_json JSON NOT NULL,
    correct_option_ids_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (attempt_question_id),
    UNIQUE KEY uq_attempt_questions_sequence (attempt_id, sequence),
    UNIQUE KEY uq_attempt_questions_question (attempt_id, question_id),
    CONSTRAINT fk_attempt_questions_attempt FOREIGN KEY (attempt_id)
        REFERENCES assessment_attempts (attempt_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_attempt_questions_question FOREIGN KEY (question_id)
        REFERENCES questions (question_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_attempt_questions_sequence CHECK (sequence >= 1),
    CONSTRAINT chk_attempt_questions_marks CHECK (marks > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE assessment_responses (
    response_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    attempt_question_id BIGINT UNSIGNED NOT NULL,
    selected_option_id BIGINT UNSIGNED NULL,
    is_correct TINYINT UNSIGNED NULL,
    marks_awarded DECIMAL(8,2) NULL,
    answered_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (response_id),
    UNIQUE KEY uq_assessment_responses_question (attempt_id, attempt_question_id),
    KEY idx_assessment_responses_attempt (attempt_id),
    CONSTRAINT fk_assessment_responses_attempt FOREIGN KEY (attempt_id)
        REFERENCES assessment_attempts (attempt_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assessment_responses_attempt_question FOREIGN KEY (attempt_question_id)
        REFERENCES assessment_attempt_questions (attempt_question_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_assessment_responses_correct CHECK (
        is_correct IS NULL OR is_correct IN (0, 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE assessment_attempt_status_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (history_id),
    KEY idx_attempt_status_history_attempt (attempt_id, created_at),
    CONSTRAINT fk_attempt_status_history_attempt FOREIGN KEY (attempt_id)
        REFERENCES assessment_attempts (attempt_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_attempt_status_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_attempt_status_history_to CHECK (to_status IN ('in_progress', 'submitted', 'timed_out')),
    CONSTRAINT chk_attempt_status_history_from CHECK (
        from_status IS NULL OR from_status IN ('in_progress', 'submitted', 'timed_out')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s.u');
        $this->execute(sprintf(
            "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
             VALUES (%s, %s, 0, %s)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
            $this->quote('assessment.attempt.own'),
            $this->quote('Start and submit own assessment attempts on Active Enrolments'),
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
                $this->quote('assessment.attempt.own'),
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
            $this->quote('assessment.attempt.own'),
        ));
        $this->execute(sprintf(
            'DELETE FROM permissions WHERE permission_key = %s',
            $this->quote('assessment.attempt.own'),
        ));
        $this->execute('DROP TABLE IF EXISTS assessment_attempt_status_history');
        $this->execute('DROP TABLE IF EXISTS assessment_responses');
        $this->execute('DROP TABLE IF EXISTS assessment_attempt_questions');
        $this->execute('DROP TABLE IF EXISTS assessment_attempts');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
