<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Assessments;

use Academy\Domain\Assessments\AssessmentAttempt;
use Academy\Domain\Assessments\AssessmentAttemptRepository;
use Academy\Domain\Assessments\AssessmentAttemptStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoAssessmentAttemptRepository implements AssessmentAttemptRepository
{
    private const COLUMNS = 'attempt_id, assessment_id, enrolment_id, content_id, attempt_number, status,
        score_percent, marks_awarded, marks_available, passed_flag, deadline_at, started_at, submitted_at,
        in_progress_marker, pass_threshold_percent, row_version, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $attemptId): ?AssessmentAttempt
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM assessment_attempts WHERE attempt_id = :id');
        $stmt->execute(['id' => $attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findInProgress(int $assessmentId, int $enrolmentId): ?AssessmentAttempt
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_attempts
             WHERE assessment_id = :assessment_id AND enrolment_id = :enrolment_id
               AND in_progress_marker = 1 LIMIT 1',
        );
        $stmt->execute([
            'assessment_id' => $assessmentId,
            'enrolment_id' => $enrolmentId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByAssessmentAndEnrolment(int $assessmentId, int $enrolmentId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_attempts
             WHERE assessment_id = :assessment_id AND enrolment_id = :enrolment_id
             ORDER BY attempt_number ASC',
        );
        $stmt->execute([
            'assessment_id' => $assessmentId,
            'enrolment_id' => $enrolmentId,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function nextAttemptNumber(int $assessmentId, int $enrolmentId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(attempt_number), 0) + 1 AS next_number
             FROM assessment_attempts
             WHERE assessment_id = :assessment_id AND enrolment_id = :enrolment_id',
        );
        $stmt->execute([
            'assessment_id' => $assessmentId,
            'enrolment_id' => $enrolmentId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['next_number'] ?? 1);
    }

    public function countSubmittedOrTimedOut(int $assessmentId, int $enrolmentId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM assessment_attempts
             WHERE assessment_id = :assessment_id AND enrolment_id = :enrolment_id
               AND status IN (:submitted, :timed_out)',
        );
        $stmt->execute([
            'assessment_id' => $assessmentId,
            'enrolment_id' => $enrolmentId,
            'submitted' => AssessmentAttemptStatus::SUBMITTED,
            'timed_out' => AssessmentAttemptStatus::TIMED_OUT,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findLatestSubmitted(int $assessmentId, int $enrolmentId): ?AssessmentAttempt
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM assessment_attempts
             WHERE assessment_id = :assessment_id AND enrolment_id = :enrolment_id
               AND status = :submitted
             ORDER BY attempt_number DESC LIMIT 1',
        );
        $stmt->execute([
            'assessment_id' => $assessmentId,
            'enrolment_id' => $enrolmentId,
            'submitted' => AssessmentAttemptStatus::SUBMITTED,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function insertInProgress(array $data): int
    {
        $pdo = $this->connections->connection();
        $utc = new DateTimeZone('UTC');
        $now = $data['started_at']->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $deadline = $data['deadline_at'];
        $stmt = $pdo->prepare(
            'INSERT INTO assessment_attempts (
                assessment_id, enrolment_id, content_id, attempt_number, status,
                score_percent, marks_awarded, marks_available, passed_flag, deadline_at,
                started_at, submitted_at, in_progress_marker, pass_threshold_percent,
                row_version, created_at, updated_at
             ) VALUES (
                :assessment_id, :enrolment_id, :content_id, :attempt_number, :status,
                NULL, NULL, NULL, NULL, :deadline_at,
                :started_at, NULL, 1, :pass_threshold_percent,
                1, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'assessment_id' => $data['assessment_id'],
            'enrolment_id' => $data['enrolment_id'],
            'content_id' => $data['content_id'],
            'attempt_number' => $data['attempt_number'],
            'status' => AssessmentAttemptStatus::IN_PROGRESS,
            'deadline_at' => $deadline === null ? null : $deadline->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'started_at' => $now,
            'pass_threshold_percent' => $data['pass_threshold_percent'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function markSubmitted(int $attemptId, array $data, int $expectedRowVersion): bool
    {
        $pdo = $this->connections->connection();
        $utc = new DateTimeZone('UTC');
        $stamp = $data['submitted_at']->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE assessment_attempts SET
                status = :status,
                score_percent = :score_percent,
                marks_awarded = :marks_awarded,
                marks_available = :marks_available,
                passed_flag = :passed_flag,
                submitted_at = :submitted_at,
                in_progress_marker = NULL,
                row_version = row_version + 1,
                updated_at = :updated_at
             WHERE attempt_id = :attempt_id
               AND status = :in_progress
               AND row_version = :row_version',
        );
        $stmt->execute([
            'status' => AssessmentAttemptStatus::SUBMITTED,
            'score_percent' => $data['score_percent'],
            'marks_awarded' => $data['marks_awarded'],
            'marks_available' => $data['marks_available'],
            'passed_flag' => $data['passed_flag'] ? 1 : 0,
            'submitted_at' => $stamp,
            'updated_at' => $stamp,
            'attempt_id' => $attemptId,
            'in_progress' => AssessmentAttemptStatus::IN_PROGRESS,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): AssessmentAttempt
    {
        $utc = new DateTimeZone('UTC');

        return new AssessmentAttempt(
            attemptId: (int) $row['attempt_id'],
            assessmentId: (int) $row['assessment_id'],
            enrolmentId: (int) $row['enrolment_id'],
            contentId: (int) $row['content_id'],
            attemptNumber: (int) $row['attempt_number'],
            status: (string) $row['status'],
            scorePercent: $row['score_percent'] === null ? null : (string) $row['score_percent'],
            marksAwarded: $row['marks_awarded'] === null ? null : (string) $row['marks_awarded'],
            marksAvailable: $row['marks_available'] === null ? null : (string) $row['marks_available'],
            passedFlag: $row['passed_flag'] === null ? null : ((int) $row['passed_flag'] === 1),
            deadlineAt: $row['deadline_at'] === null ? null : new DateTimeImmutable((string) $row['deadline_at'], $utc),
            startedAt: new DateTimeImmutable((string) $row['started_at'], $utc),
            submittedAt: $row['submitted_at'] === null ? null : new DateTimeImmutable((string) $row['submitted_at'], $utc),
            inProgressMarker: $row['in_progress_marker'] === null ? null : (int) $row['in_progress_marker'],
            passThresholdPercent: (string) $row['pass_threshold_percent'],
            rowVersion: (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
