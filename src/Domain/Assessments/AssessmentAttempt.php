<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

final class AssessmentAttempt
{
    public function __construct(
        public readonly int $attemptId,
        public readonly int $assessmentId,
        public readonly int $enrolmentId,
        public readonly int $contentId,
        public readonly int $attemptNumber,
        public readonly string $status,
        public readonly ?string $scorePercent,
        public readonly ?string $marksAwarded,
        public readonly ?string $marksAvailable,
        public readonly ?bool $passedFlag,
        public readonly ?DateTimeImmutable $deadlineAt,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly ?int $inProgressMarker,
        public readonly string $passThresholdPercent,
        public readonly int $rowVersion,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isInProgress(): bool
    {
        return $this->status === AssessmentAttemptStatus::IN_PROGRESS;
    }

    public function isSubmitted(): bool
    {
        return $this->status === AssessmentAttemptStatus::SUBMITTED;
    }
}
