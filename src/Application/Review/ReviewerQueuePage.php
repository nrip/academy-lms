<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Domain\Review\ReviewerQueueItem;

/**
 * Paginated reviewer queue result for R-01.
 */
final class ReviewerQueuePage
{
    /**
     * @param list<ReviewerQueueItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly string $filter,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }
}
