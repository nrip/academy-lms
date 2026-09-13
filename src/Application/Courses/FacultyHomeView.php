<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

final class FacultyHomeView
{
    /**
     * @param list<CourseAdminCourseRow> $courses
     * @param list<FacultyUpcomingSession> $upcomingSessions
     * @param list<FacultyActivityItem> $recentActivity
     */
    public function __construct(
        public readonly array $courses,
        public readonly array $upcomingSessions,
        public readonly int $learnersEnrolled,
        public readonly array $recentActivity,
    ) {
    }
}
