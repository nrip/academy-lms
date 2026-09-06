<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

interface CourseVersionRepository
{
    public function findById(int $versionId): ?CourseVersion;

    /**
     * @return list<CourseVersion>
     */
    public function listByCourseId(int $courseId): array;

    public function nextVersionNumber(int $courseId): int;

    /**
     * Inserts a Draft CourseVersion (unlocked). Returns version_id.
     *
     * @param array{
     *   course_id: int,
     *   version_number: int,
     *   title: string,
     *   description: string,
     *   learning_objectives: string,
     *   intended_audience: string,
     *   syllabus_summary: string,
     *   admission_mode: string,
     *   delivery_type: string,
     *   duration_text: string,
     *   validity_period_days: ?int,
     *   standard_fee: string,
     *   gst_rate: string,
     *   currency: string,
     *   certificate_type: string,
     *   faq: ?array<string, mixed>,
     *   cloned_from_version_id?: ?int
     * } $fields
     */
    public function insertDraft(array $fields): int;

    /**
     * Updates overview fields on an unlocked Draft version only.
     * Returns false when no row matched (locked or not draft).
     *
     * @param array{
     *   title: string,
     *   description: string,
     *   learning_objectives: string,
     *   intended_audience: string,
     *   syllabus_summary: string,
     *   delivery_type: string,
     *   duration_text: string,
     *   validity_period_days: ?int,
     *   standard_fee: string,
     *   gst_rate: string,
     *   currency: string,
     *   certificate_type: string
     * } $fields
     */
    public function updateDraftOverview(int $versionId, array $fields): bool;

    /**
     * Marks a currently-unlocked version as locked. No-op guard against
     * re-locking is the caller's responsibility (idempotency at the service layer).
     */
    public function lock(int $versionId, string $lockedReason, DateTimeImmutable $lockedAt): void;

    /**
     * Draft → Published + lock in one update. Returns false when no row matched.
     */
    public function publishAndLock(int $versionId, DateTimeImmutable $at): bool;
}
