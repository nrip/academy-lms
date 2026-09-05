<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class AssessmentAttemptTransitionResult
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly DateTimeImmutable $at,
    ) {
    }
}
