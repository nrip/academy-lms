<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface CourseDocumentRequirementRepository
{
    /**
     * @return list<CourseDocumentRequirement>
     */
    public function listByCourseVersionId(int $courseVersionId): array;
}
