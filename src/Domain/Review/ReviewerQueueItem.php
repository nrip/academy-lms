<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

/**
 * Summary row for R-01 reviewer queue (no learner PII).
 */
final class ReviewerQueueItem
{
    public function __construct(
        public readonly int $applicationId,
        public readonly string $applicationNumber,
        public readonly string $courseTitle,
        public readonly string $batchLabel,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly string $status,
        public readonly ?int $assignmentId,
        public readonly ?int $assignedReviewerUserId,
        public readonly ?string $slaAgeBand,
        public readonly string $documentCompletenessSummary,
    ) {
    }
}
