<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface CourseDocumentRequirementRepository
{
    /**
     * @return list<CourseDocumentRequirement>
     */
    public function listByCourseVersionId(int $courseVersionId): array;

    /**
     * @param array{
     *   course_version_id: int,
     *   document_name: string,
     *   description: string,
     *   mandatory_flag: bool,
     *   accepted_file_types: string,
     *   max_size_bytes: int,
     *   single_or_multiple: string,
     *   reuse_allowed: bool,
     *   reviewer_instructions: ?string,
     *   sort_order: int
     * } $data
     */
    public function insert(array $data): int;

    public function findById(int $requirementId): ?CourseDocumentRequirement;

    /**
     * Updates the customer-facing fields only. File types, size, and reuse stay as stored.
     */
    public function updatePresentation(
        int $requirementId,
        string $documentName,
        string $description,
        bool $mandatory,
        int $sortOrder,
    ): bool;

    public function delete(int $requirementId): bool;
}
