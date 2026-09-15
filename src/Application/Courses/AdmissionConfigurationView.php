<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Domain\Courses\Course;
use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Courses\CourseVersion;

final class AdmissionConfigurationView
{
    /**
     * @param list<string> $categoryOptions
     * @param list<string> $selectedCategories
     * @param list<CourseDocumentRequirement> $documents
     */
    public function __construct(
        public readonly Course $course,
        public readonly CourseVersion $version,
        public readonly array $categoryOptions,
        public readonly array $selectedCategories,
        public readonly string $notes,
        public readonly bool $hasUnlistedCategories,
        public readonly array $documents,
        public readonly bool $editable,
        public readonly int $nextDisplayOrder,
    ) {
    }
}
