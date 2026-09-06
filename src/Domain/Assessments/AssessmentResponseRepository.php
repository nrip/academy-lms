<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

interface AssessmentResponseRepository
{
    /**
     * @return list<AssessmentResponse>
     */
    public function listByAttemptId(int $attemptId): array;

    public function upsertSelection(
        int $attemptId,
        int $attemptQuestionId,
        ?int $selectedOptionId,
        DateTimeImmutable $at,
    ): AssessmentResponse;

    /**
     * @param array<int, array{is_correct: bool, marks_awarded: string}> $scoredByAttemptQuestionId
     */
    public function applyScores(int $attemptId, array $scoredByAttemptQuestionId): void;
}
