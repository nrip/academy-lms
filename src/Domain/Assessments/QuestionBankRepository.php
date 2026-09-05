<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

interface QuestionBankRepository
{
    public function findById(int $bankId): ?QuestionBank;

    public function findByCourseId(int $courseId): ?QuestionBank;

    public function insert(int $courseId, string $title): int;
}
