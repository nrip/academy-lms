<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

/**
 * System-calculated progress copy for the learner dashboard (not learner-owned goals).
 */
final class LearnerProgressNarrative
{
    public static function forStudyCard(
        int $completedCount,
        int $totalCount,
        int $progressPercent,
        ?string $continueTitle,
        ?string $continueChapterTitle,
        bool $contentAccessible,
        int $certificateCount,
    ): string {
        if ($totalCount <= 0) {
            return 'Curriculum will appear here when lessons are published.';
        }

        if ($progressPercent >= 100) {
            if ($certificateCount > 0) {
                return 'All lessons complete — your certificate is ready.';
            }

            return 'All lessons complete.';
        }

        $lessonCount = $completedCount . ' of ' . $totalCount . ' lessons complete';

        if (!$contentAccessible) {
            return $lessonCount . ' · Access opens when your batch begins.';
        }

        if ($continueTitle !== null && $continueTitle !== '') {
            if ($continueChapterTitle !== null && $continueChapterTitle !== '') {
                return 'Continue with ' . $continueTitle . ' · Chapter: ' . $continueChapterTitle;
            }

            return 'Continue with ' . $continueTitle;
        }

        return $lessonCount;
    }
}
