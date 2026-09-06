<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class AssessmentQuestionLink
{
    public function __construct(
        public readonly int $linkId,
        public readonly int $assessmentId,
        public readonly int $questionId,
        public readonly int $sequence,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
