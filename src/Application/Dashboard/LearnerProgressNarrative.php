<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

/**
 * System-calculated progress copy for the learner dashboard and outline (not learner-owned goals).
 */
final class LearnerProgressNarrative
{
    public static function forStudyCard(
        int $completedCount,
        int $totalCount,
        int $progressPercent,
        ?string $continueTitle,
        ?string $continueChapterTitle,
        ?int $continueChapterIndex,
        int $chapterTotal,
        bool $contentAccessible,
        int $certificateCount,
    ): string {
        if ($totalCount <= 0) {
            return 'Curriculum will appear here when lessons are published.';
        }

        if ($progressPercent >= 100) {
            if ($certificateCount > 0) {
                return 'Course complete — your certificate is ready.';
            }

            return 'All lessons complete.';
        }

        if (!$contentAccessible) {
            return self::chapterLine($continueChapterIndex, $chapterTotal, $continueChapterTitle)
                . 'Access opens when your batch begins.';
        }

        if ($continueTitle !== null && $continueTitle !== '') {
            $parts = [];
            $chapter = self::chapterLine($continueChapterIndex, $chapterTotal, $continueChapterTitle);
            if ($chapter !== '') {
                $parts[] = rtrim($chapter, ' ·');
            }
            $parts[] = 'Continue with: ' . $continueTitle;

            return implode(' · ', $parts);
        }

        if ($chapterTotal > 0 && $continueChapterIndex !== null) {
            return 'Chapter ' . $continueChapterIndex . ' of ' . $chapterTotal;
        }

        return $completedCount . ' of ' . $totalCount . ' lessons complete';
    }

    /**
     * Compact progress headline for the course outline.
     */
    public static function forOutline(
        int $completedCount,
        int $totalCount,
        int $progressPercent,
        ?int $continueChapterIndex,
        int $chapterTotal,
        ?string $continueTitle,
        int $certificateCount,
    ): string {
        if ($totalCount <= 0) {
            return 'Lessons will appear when the curriculum is ready.';
        }

        if ($progressPercent >= 100) {
            return $certificateCount > 0
                ? 'Course complete — view your certificate'
                : 'All lessons complete';
        }

        if ($continueChapterIndex !== null && $chapterTotal > 0) {
            $line = 'Chapter ' . $continueChapterIndex . ' of ' . $chapterTotal;
            if ($continueTitle !== null && $continueTitle !== '') {
                return $line . ' · Next: ' . $continueTitle;
            }

            return $line;
        }

        return $completedCount . ' of ' . $totalCount . ' lessons complete';
    }

    private static function chapterLine(?int $chapterIndex, int $chapterTotal, ?string $chapterTitle): string
    {
        if ($chapterIndex !== null && $chapterTotal > 0) {
            return 'Chapter ' . $chapterIndex . ' of ' . $chapterTotal . ' · ';
        }
        if ($chapterTitle !== null && $chapterTitle !== '') {
            return 'Chapter: ' . $chapterTitle . ' · ';
        }

        return '';
    }
}
