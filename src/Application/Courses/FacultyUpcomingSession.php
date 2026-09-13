<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use DateTimeImmutable;

final class FacultyUpcomingSession
{
    public function __construct(
        public readonly string $courseTitle,
        public readonly string $chapterTitle,
        public readonly string $lessonTitle,
        public readonly DateTimeImmutable $startsAt,
        public readonly string $href,
    ) {
    }
}
