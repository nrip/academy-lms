<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

final class LearnerStudyCard
{
    public function __construct(
        public readonly int $enrolmentId,
        public readonly string $courseTitle,
        public readonly string $batchName,
        public readonly string $statusLabel,
        public readonly string $statusExplanation,
        public readonly string $statusSeverity,
        public readonly bool $contentAccessible,
        public readonly ?string $coverPath,
        public readonly int $completedCount,
        public readonly int $totalCount,
        public readonly int $progressPercent,
        public readonly ?string $continueTitle,
        public readonly ?string $continueChapterTitle,
        public readonly string $continueHref,
        public readonly int $certificateCount,
        public readonly string $certificatesHref,
    ) {
    }
}
