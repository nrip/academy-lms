<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

interface QuestionOptionRepository
{
    /** @return list<QuestionOption> */
    public function listByQuestionId(int $questionId): array;

    /**
     * @param array{
     *   question_id: int,
     *   sequence: int,
     *   option_text: string,
     *   is_correct: bool
     * } $data
     */
    public function insert(array $data): int;

    public function deleteByQuestionId(int $questionId): void;
}
