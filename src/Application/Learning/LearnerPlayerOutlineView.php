<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Learning\Enrolment;
use DateTimeImmutable;

final class LearnerPlayerOutlineView
{
    /**
     * @param list<LearnerPlayerModuleView> $modules
     */
    public function __construct(
        public readonly Enrolment $enrolment,
        public readonly string $courseTitle,
        public readonly string $versionTitle,
        public readonly string $courseSlug,
        public readonly bool $hasCover,
        public readonly bool $contentAccessible,
        public readonly ?string $accessMessage,
        public readonly int $completedCount,
        public readonly int $totalCount,
        public readonly array $modules,
    ) {
    }

    public function progressPercent(): int
    {
        return OutlineProgress::percent($this->completedCount, $this->totalCount);
    }

    /**
     * First accessible incomplete lesson. Same target the outline uses.
     *
     * @return array{
     *   contentId: int,
     *   title: string,
     *   chapterTitle: string,
     *   chapterIndex: int,
     *   chapterTotal: int
     * }|null
     */
    public function continueTarget(): ?array
    {
        if (!$this->contentAccessible) {
            return null;
        }

        $chapterTotal = count($this->modules);
        $chapterIndex = 0;
        foreach ($this->modules as $moduleView) {
            ++$chapterIndex;
            if (!$moduleView->unlocked) {
                continue;
            }
            foreach ($moduleView->items as $itemView) {
                if ($itemView->accessible && !$itemView->completed) {
                    return [
                        'contentId' => $itemView->item->contentId,
                        'title' => $itemView->item->title,
                        'chapterTitle' => $moduleView->module->title,
                        'chapterIndex' => $chapterIndex,
                        'chapterTotal' => $chapterTotal,
                    ];
                }
            }
        }

        return null;
    }

    public function chapterTotal(): int
    {
        return count($this->modules);
    }

    /**
     * 1-based index of the chapter containing the continue target, or null when complete/unavailable.
     */
    public function continueChapterIndex(): ?int
    {
        $continue = $this->continueTarget();

        return $continue['chapterIndex'] ?? null;
    }

    /**
     * Accessible live lessons that have not ended. Join URLs stay off this list.
     *
     * @return list<array{contentId: int, title: string, chapterTitle: string, startsAt: DateTimeImmutable}>
     */
    public function upcomingLiveSessions(DateTimeImmutable $now): array
    {
        if (!$this->contentAccessible) {
            return [];
        }

        $sessions = [];
        foreach ($this->modules as $moduleView) {
            if (!$moduleView->unlocked) {
                continue;
            }
            foreach ($moduleView->items as $itemView) {
                if (!$itemView->accessible || $itemView->item->contentType !== ContentItemType::LIVE_SESSION) {
                    continue;
                }
                $starts = $itemView->item->delivery->liveStartsAt;
                if ($starts === null) {
                    continue;
                }
                $ends = $itemView->item->delivery->liveEndsAt;
                if ($starts < $now && ($ends === null || $ends < $now)) {
                    continue;
                }
                $sessions[] = [
                    'contentId' => $itemView->item->contentId,
                    'title' => $itemView->item->title,
                    'chapterTitle' => $moduleView->module->title,
                    'startsAt' => $starts,
                ];
            }
        }

        usort(
            $sessions,
            static fn (array $left, array $right): int => $left['startsAt'] <=> $right['startsAt'],
        );

        return $sessions;
    }
}
