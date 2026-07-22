<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

interface ReviewerQueueQuery
{
    /**
     * @return list<ReviewerQueueItem>
     */
    public function listForReviewer(
        int $reviewerUserId,
        string $filter,
        DateTimeImmutable $now,
        int $limit = 50,
        int $offset = 0,
    ): array;
}
