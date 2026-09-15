<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

interface CourseAdminScopeAssignmentRepository
{
    /**
     * @return list<CourseAdminScopeAssignment>
     */
    public function listActiveForAdmin(int $adminUserId, DateTimeImmutable $at): array;

    public function insertCourseScope(
        int $adminUserId,
        int $courseId,
        bool $includeFutureVersions,
        DateTimeImmutable $effectiveFrom,
        int $createdByUserId,
    ): int;

    public function insertCourseVersionScope(
        int $adminUserId,
        int $courseVersionId,
        DateTimeImmutable $effectiveFrom,
        int $createdByUserId,
    ): int;

    public function findActiveCourseScope(int $adminUserId, int $courseId, DateTimeImmutable $at): ?CourseAdminScopeAssignment;

    /**
     * Distinct admin user ids with an active course or version scope covering this course.
     *
     * @return list<int>
     */
    public function listActiveAdminUserIdsForCourse(int $courseId, DateTimeImmutable $at): array;

    public function revoke(int $scopeAssignmentId, int $revokedByUserId, string $reason, DateTimeImmutable $revokedAt): void;
}
