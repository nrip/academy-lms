<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Domain\Courses\ContentItem;

final class LearnerPlayerItemView
{
    public function __construct(
        public readonly ContentItem $item,
        public readonly bool $accessible,
        public readonly string $completionStatus,
        public readonly bool $completed,
    ) {
    }
}
