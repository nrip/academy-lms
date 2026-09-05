<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseVersion;

final class CourseAdminCourseDetail
{
    /**
     * @param list<CourseVersion> $versions
     */
    public function __construct(
        public readonly Course $course,
        public readonly array $versions,
    ) {
    }
}
