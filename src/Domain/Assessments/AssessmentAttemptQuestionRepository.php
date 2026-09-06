<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

interface AssessmentAttemptQuestionRepository
{
    /**
     * @return list<AssessmentAttemptQuestion>
     */
    public function listByAttemptId(int $attemptId): array;

    /**
     * @param array{
     *   attempt_id: int,
     *   question_id: int,
     *   question_version: int,
     *   sequence: int,
     *   stem: string,
     *   marks: string,
     *   options: list<array{option_id: int, sequence: int, option_text: string}>,
     *   correct_option_ids: list<int>
     * } $data
     */
    public function insert(array $data): int;
}
