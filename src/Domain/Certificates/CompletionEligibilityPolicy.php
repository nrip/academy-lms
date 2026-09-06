<?php

declare(strict_types=1);

namespace Academy\Domain\Certificates;

use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Learning\ContentProgress;

/**
 * Phase 1 Model A: all mandatory ContentItems completed via ContentProgress.
 */
final class CompletionEligibilityPolicy
{
    /**
     * @param list<ContentItem> $contentItems
     * @param array<int, ContentProgress> $progressByContentId
     */
    public function isEligible(array $contentItems, array $progressByContentId): bool
    {
        $mandatory = array_values(array_filter(
            $contentItems,
            static fn (ContentItem $item): bool => $item->mandatoryFlag,
        ));
        if ($mandatory === []) {
            return false;
        }

        foreach ($mandatory as $item) {
            $progress = $progressByContentId[$item->contentId] ?? null;
            if ($progress === null || !$progress->isCompleted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<ContentItem> $contentItems
     * @param array<int, ContentProgress> $progressByContentId
     * @return list<string>
     */
    public function incompleteTitles(array $contentItems, array $progressByContentId): array
    {
        $titles = [];
        foreach ($contentItems as $item) {
            if (!$item->mandatoryFlag) {
                continue;
            }
            $progress = $progressByContentId[$item->contentId] ?? null;
            if ($progress === null || !$progress->isCompleted()) {
                $titles[] = $item->title;
            }
        }

        return $titles;
    }
}
