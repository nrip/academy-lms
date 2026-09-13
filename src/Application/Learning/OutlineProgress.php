<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

/**
 * The outline progress formula. Dashboard and player must both call this.
 * 100% means every lesson in the outline is marked complete. It is not certificate eligibility.
 */
final class OutlineProgress
{
    public static function percent(int $completedCount, int $totalCount): int
    {
        if ($totalCount <= 0 || $completedCount <= 0) {
            return 0;
        }
        if ($completedCount >= $totalCount) {
            return 100;
        }

        return (int) floor(($completedCount / $totalCount) * 100);
    }
}
