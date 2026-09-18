<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class LearnerEnrolmentGoal
{
    public function __construct(
        public readonly int $goalId,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly string $label,
        public readonly string $targetDate,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
