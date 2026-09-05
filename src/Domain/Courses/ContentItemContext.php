<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

/**
 * Content item placement within a CourseVersion (for assessment authoring access checks).
 */
final class ContentItemContext
{
    public function __construct(
        public readonly ContentItem $contentItem,
        public readonly int $courseId,
        public readonly int $courseVersionId,
        public readonly bool $versionLocked,
    ) {
    }
}
