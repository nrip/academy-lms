<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

final class CourseAdminCourseRow
{
    public function __construct(
        public readonly int $courseId,
        public readonly string $title,
        public readonly string $code,
        public readonly bool $published,
        public readonly ?string $nextBatchName,
        public readonly int $learnerCount,
    ) {
    }
}
