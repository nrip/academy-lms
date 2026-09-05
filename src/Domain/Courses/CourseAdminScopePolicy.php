<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

/**
 * WP-L1 Course Admin object scope (Course | CourseVersion).
 * include_future_versions default false; course-level without the flag matches
 * only versions whose created_at is on or before the assignment effective_from.
 * Draft versions are included when they satisfy that rule (unlike reviewer publish-time rule).
 */
final class CourseAdminScopePolicy
{
    public function __construct(
        private readonly CourseAdminScopeAssignmentRepository $assignments,
        private readonly CourseVersionRepository $courseVersions,
    ) {
    }

    public function isCourseInScope(int $adminUserId, int $courseId, DateTimeImmutable $at): bool
    {
        foreach ($this->assignments->listActiveForAdmin($adminUserId, $at) as $assignment) {
            if ($assignment->scopeType === CourseAdminScopeType::COURSE
                && $assignment->courseId === $courseId
            ) {
                return true;
            }

            if ($assignment->scopeType === CourseAdminScopeType::COURSE_VERSION
                && $assignment->courseVersionId !== null
            ) {
                $version = $this->courseVersions->findById($assignment->courseVersionId);
                if ($version !== null && $version->courseId === $courseId) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isVersionInScope(
        int $adminUserId,
        int $courseId,
        int $courseVersionId,
        DateTimeImmutable $at,
    ): bool {
        $version = $this->courseVersions->findById($courseVersionId);
        if ($version === null || $version->courseId !== $courseId) {
            return false;
        }

        foreach ($this->assignments->listActiveForAdmin($adminUserId, $at) as $assignment) {
            if ($assignment->scopeType === CourseAdminScopeType::COURSE_VERSION
                && $assignment->courseVersionId === $courseVersionId
            ) {
                return true;
            }

            if ($assignment->scopeType === CourseAdminScopeType::COURSE
                && $assignment->courseId === $courseId
            ) {
                if ($assignment->includeFutureVersions) {
                    return true;
                }

                // Versions created at or before assignment time are covered.
                if ($version->createdAt <= $assignment->effectiveFrom) {
                    return true;
                }
            }
        }

        return false;
    }
}
