<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

/**
 * Course-scoped analytics derived from existing tables (PX-ANALYTICS-1).
 */
final class CourseAnalyticsView
{
    /**
     * @param list<array{label: string, count: int}> $progressBuckets
     * @param list<FacultyActivityItem> $recentActivity
     */
    public function __construct(
        public readonly int $courseId,
        public readonly string $courseTitle,
        public readonly int $learnersEnrolled,
        public readonly int $activeLearners,
        public readonly int $certificatesIssued,
        public readonly float $completionRatePercent,
        public readonly int $lessonsCompletedTotal,
        public readonly int $assessmentAttemptsSubmitted,
        public readonly int $assessmentAttemptsPassed,
        public readonly ?float $averageScorePercent,
        public readonly int $openQuestions,
        public readonly int $learnersActiveLast7Days,
        public readonly array $progressBuckets,
        public readonly array $recentActivity,
    ) {
    }
}
