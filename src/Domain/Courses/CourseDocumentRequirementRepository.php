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
}
