<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * WP-L5 — Assessment configuration authoring on mcq_assessment ContentItems.
 */
final class WpL5AssessmentConfiguration extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE assessments (
    assessment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    content_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    questions_per_attempt INT UNSIGNED NOT NULL,
    pass_threshold_percent DECIMAL(5,2) NOT NULL,
    time_limit_seconds INT UNSIGNED NULL,
    max_attempts INT UNSIGNED NOT NULL,
    cooldown_seconds INT UNSIGNED NULL,
    randomise_questions TINYINT(1) NOT NULL DEFAULT 0,
    randomise_options TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (assessment_id),
    UNIQUE KEY uq_assessments_content_id (content_id),
    CONSTRAINT fk_assessments_content_id FOREIGN KEY (content_id)
        REFERENCES content_items (content_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_assessments_questions_per_attempt CHECK (questions_per_attempt >= 1),
    CONSTRAINT chk_assessments_pass_threshold CHECK (pass_threshold_percent >= 0 AND pass_threshold_percent <= 100),
    CONSTRAINT chk_assessments_max_attempts CHECK (max_attempts >= 1),
    CONSTRAINT chk_assessments_randomise_questions CHECK (randomise_questions IN (0, 1)),
    CONSTRAINT chk_assessments_randomise_options CHECK (randomise_options IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE assessment_question_links (
    link_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (link_id),
    UNIQUE KEY uq_assessment_question_links_pair (assessment_id, question_id),
    UNIQUE KEY uq_assessment_question_links_sequence (assessment_id, sequence),
    KEY idx_assessment_question_links_question (question_id),
    CONSTRAINT fk_assessment_question_links_assessment FOREIGN KEY (assessment_id)
        REFERENCES assessments (assessment_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assessment_question_links_question FOREIGN KEY (question_id)
        REFERENCES questions (question_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_assessment_question_links_sequence CHECK (sequence >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $lockedViaContent = <<<'SQL'
EXISTS (
    SELECT 1
    FROM content_items ci
    INNER JOIN modules m ON m.module_id = ci.module_id
    INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
    WHERE ci.content_id = %s AND cv.locked_at IS NOT NULL
)
SQL;

        $this->execute(sprintf(
            <<<'SQL'
CREATE TRIGGER trg_assessments_forbid_insert_when_locked
BEFORE INSERT ON assessments
FOR EACH ROW
BEGIN
    IF %s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add Assessment to a locked CourseVersion';
    END IF;
END
SQL,
            sprintf($lockedViaContent, 'NEW.content_id'),
        ));

        $this->execute(sprintf(
            <<<'SQL'
CREATE TRIGGER trg_assessments_forbid_update_when_locked
BEFORE UPDATE ON assessments
FOR EACH ROW
BEGIN
    IF %s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assessment belongs to a locked CourseVersion';
    END IF;
END
SQL,
            sprintf($lockedViaContent, 'OLD.content_id'),
        ));

        $this->execute(sprintf(
            <<<'SQL'
CREATE TRIGGER trg_assessments_forbid_delete_when_locked
BEFORE DELETE ON assessments
FOR EACH ROW
BEGIN
    IF %s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assessment belongs to a locked CourseVersion';
    END IF;
END
SQL,
            sprintf($lockedViaContent, 'OLD.content_id'),
        ));

        $lockedViaAssessment = <<<'SQL'
EXISTS (
    SELECT 1
    FROM assessments a
    INNER JOIN content_items ci ON ci.content_id = a.content_id
    INNER JOIN modules m ON m.module_id = ci.module_id
    INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
    WHERE a.assessment_id = %s AND cv.locked_at IS NOT NULL
)
SQL;

        $this->execute(sprintf(
            <<<'SQL'
CREATE TRIGGER trg_assessment_question_links_forbid_insert_when_locked
BEFORE INSERT ON assessment_question_links
FOR EACH ROW
BEGIN
    IF %s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot link questions on a locked CourseVersion';
    END IF;
END
SQL,
            sprintf($lockedViaAssessment, 'NEW.assessment_id'),
        ));

        $this->execute(sprintf(
            <<<'SQL'
CREATE TRIGGER trg_assessment_question_links_forbid_update_when_locked
BEFORE UPDATE ON assessment_question_links
FOR EACH ROW
BEGIN
    IF %s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assessment question links belong to a locked CourseVersion';
    END IF;
END
SQL,
            sprintf($lockedViaAssessment, 'OLD.assessment_id'),
        ));

        $this->execute(sprintf(
            <<<'SQL'
CREATE TRIGGER trg_assessment_question_links_forbid_delete_when_locked
BEFORE DELETE ON assessment_question_links
FOR EACH ROW
BEGIN
    IF %s THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assessment question links belong to a locked CourseVersion';
    END IF;
END
SQL,
            sprintf($lockedViaAssessment, 'OLD.assessment_id'),
        ));

        $now = gmdate('Y-m-d H:i:s.u');
        $this->execute(sprintf(
            "INSERT INTO permissions (permission_key, description, is_sensitive, created_at)
             VALUES (%s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sensitive = VALUES(is_sensitive)",
            $this->quote('assessment.manage'),
            $this->quote('Configure MCQ assessments on unlocked CourseVersions'),
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
                $this->quote('assessment.manage'),
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
            $this->quote('assessment.manage'),
        ));
        $this->execute(sprintf(
            'DELETE FROM permissions WHERE permission_key = %s',
            $this->quote('assessment.manage'),
        ));

        $this->execute('DROP TRIGGER IF EXISTS trg_assessment_question_links_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_assessment_question_links_forbid_update_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_assessment_question_links_forbid_insert_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_assessments_forbid_delete_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_assessments_forbid_update_when_locked');
        $this->execute('DROP TRIGGER IF EXISTS trg_assessments_forbid_insert_when_locked');
        $this->execute('DROP TABLE IF EXISTS assessment_question_links');
        $this->execute('DROP TABLE IF EXISTS assessments');
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
