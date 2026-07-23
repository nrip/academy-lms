<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

interface ReviewerScopeAssignmentRepository
{
    /**
     * Active assignments for a reviewer at the given instant (revoked_at IS NULL,
     * effective window contains $at).
     *
     * @return list<ReviewerScopeAssignment>
     */
    public function listActiveForReviewer(int $reviewerUserId, DateTimeImmutable $at): array;
}
