<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

final class ApplicationReviewAssignment
{
    public function __construct(
        public readonly int $assignmentId,
        public readonly int $applicationId,
        public readonly int $reviewerUserId,
        public readonly string $assignmentStatus,
        public readonly DateTimeImmutable $claimedAt,
        public readonly ?DateTimeImmutable $releasedAt,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly ?int $activeMarker,
        public readonly int $rowVersion,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->assignmentStatus === ApplicationReviewAssignmentStatus::ACTIVE
            && $this->activeMarker === 1;
    }
}
