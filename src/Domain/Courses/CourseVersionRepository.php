<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface CourseVersionRepository
{
    public function findById(int $versionId): ?CourseVersion;

    /**
     * @return list<CourseVersion>
     */
    public function listByCourseId(int $courseId): array;

    /**
     * Marks a currently-unlocked version as locked. No-op guard against
     * re-locking is the caller's responsibility (idempotency at the service layer).
     */
    public function lock(int $versionId, string $lockedReason, \DateTimeImmutable $lockedAt): void;
}
