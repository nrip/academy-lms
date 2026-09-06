<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface CourseRepository
{
    public function findById(int $courseId): ?Course;

    public function findBySlug(string $slug): ?Course;

    public function findByCourseCode(string $courseCode): ?Course;

    /**
     * @return list<Course>
     */
    public function listActive(): array;

    /**
     * @param list<int> $courseIds
     * @return list<Course>
     */
    public function listByIds(array $courseIds): array;

    /**
     * @return array{course_id: int}
     */
    public function insert(string $courseCode, string $slug, string $masterTitle, string $status): array;

    public function setCurrentPublishedVersionId(int $courseId, int $versionId): void;
}
