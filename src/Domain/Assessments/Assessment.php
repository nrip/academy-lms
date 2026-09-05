<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class Assessment
{
    public function __construct(
        public readonly int $assessmentId,
        public readonly int $contentId,
        public readonly string $title,
        public readonly int $questionsPerAttempt,
        public readonly string $passThresholdPercent,
        public readonly ?int $timeLimitSeconds,
        public readonly int $maxAttempts,
        public readonly ?int $cooldownSeconds,
        public readonly bool $randomiseQuestions,
        public readonly bool $randomiseOptions,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
