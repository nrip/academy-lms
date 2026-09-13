<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

final class CourseAdminHomeView
{
    /**
     * @param list<CourseAdminCourseRow> $courses
     */
    public function __construct(
        public readonly int $totalCourses,
        public readonly int $publishedCourses,
        public readonly int $activeBatches,
        public readonly int $learnersEnrolled,
        public readonly array $courses,
    ) {
    }
}
