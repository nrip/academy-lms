<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class AssessmentResponse
{
    public function __construct(
        public readonly int $responseId,
        public readonly int $attemptId,
        public readonly int $attemptQuestionId,
        public readonly ?int $selectedOptionId,
        public readonly ?bool $isCorrect,
        public readonly ?string $marksAwarded,
        public readonly ?DateTimeImmutable $answeredAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
