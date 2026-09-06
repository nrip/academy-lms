<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Domain\Courses\Module;

final class LearnerPlayerModuleView
{
    /**
     * @param list<LearnerPlayerItemView> $items
     */
    public function __construct(
        public readonly Module $module,
        public readonly bool $unlocked,
        public readonly array $items,
    ) {
    }
}
