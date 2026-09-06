<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class QuestionBank
{
    public function __construct(
        public readonly int $bankId,
        public readonly int $courseId,
        public readonly string $title,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
