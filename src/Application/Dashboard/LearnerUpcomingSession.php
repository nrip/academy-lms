<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

use DateTimeImmutable;

final class LearnerUpcomingSession
{
    public function __construct(
        public readonly string $courseTitle,
        public readonly string $lessonTitle,
        public readonly string $chapterTitle,
        public readonly DateTimeImmutable $startsAt,
        public readonly string $href,
    ) {
    }
}
