<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

interface AssessmentQuestionLinkRepository
{
    /** @return list<AssessmentQuestionLink> */
    public function listByAssessmentId(int $assessmentId): array;

    public function deleteByAssessmentId(int $assessmentId): void;

    /**
     * @param array{assessment_id: int, question_id: int, sequence: int} $data
     */
    public function insert(array $data): int;
}
