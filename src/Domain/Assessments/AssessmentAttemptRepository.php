<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

interface AssessmentAttemptRepository
{
    public function findById(int $attemptId): ?AssessmentAttempt;

    public function findInProgress(int $assessmentId, int $enrolmentId): ?AssessmentAttempt;

    /**
     * @return list<AssessmentAttempt>
     */
    public function listByAssessmentAndEnrolment(int $assessmentId, int $enrolmentId): array;

    public function nextAttemptNumber(int $assessmentId, int $enrolmentId): int;

    public function countSubmittedOrTimedOut(int $assessmentId, int $enrolmentId): int;

    public function findLatestSubmitted(int $assessmentId, int $enrolmentId): ?AssessmentAttempt;

    /**
     * @param array{
     *   assessment_id: int,
     *   enrolment_id: int,
     *   content_id: int,
     *   attempt_number: int,
     *   pass_threshold_percent: string,
     *   started_at: DateTimeImmutable,
     *   deadline_at: ?DateTimeImmutable
     * } $data
     */
    public function insertInProgress(array $data): int;

    /**
     * @param array{
     *   score_percent: string,
     *   marks_awarded: string,
     *   marks_available: string,
     *   passed_flag: bool,
     *   submitted_at: DateTimeImmutable
     * } $data
     */
    public function markSubmitted(int $attemptId, array $data, int $expectedRowVersion): bool;
}
