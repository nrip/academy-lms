<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use DateTimeImmutable;

final class FacultyQuestionQueueItemView
{
    public function __construct(
        public readonly int $questionId,
        public readonly string $courseTitle,
        public readonly string $chapterTitle,
        public readonly string $lessonTitle,
        public readonly string $learnerName,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly DateTimeImmutable $askedAt,
    ) {
    }
}
