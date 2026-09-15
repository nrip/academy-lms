<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface LearningQuestionResponseRepository
{
    public function insert(int $questionId, int $respondedByUserId, string $body, DateTimeImmutable $at): int;

    /**
     * @return list<LearningQuestionResponse>
     */
    public function listForQuestion(int $questionId): array;

    public function countForQuestion(int $questionId): int;
}
