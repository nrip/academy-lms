<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class QuestionOption
{
    public function __construct(
        public readonly int $optionId,
        public readonly int $questionId,
        public readonly int $sequence,
        public readonly string $optionText,
        public readonly bool $isCorrect,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
