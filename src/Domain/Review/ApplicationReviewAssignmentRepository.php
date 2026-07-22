<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

interface ApplicationReviewAssignmentRepository
{
    public function findActiveForApplication(int $applicationId): ?ApplicationReviewAssignment;

    public function lockActiveForApplication(int $applicationId): ?ApplicationReviewAssignment;

    public function claim(
        int $applicationId,
        int $reviewerUserId,
        DateTimeImmutable $now,
    ): ApplicationReviewAssignment;

    public function release(
        int $assignmentId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    public function complete(
        int $assignmentId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;
}
