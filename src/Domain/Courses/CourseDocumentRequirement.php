<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class CourseDocumentRequirement
{
    public function __construct(
        public readonly int $requirementId,
        public readonly int $courseVersionId,
        public readonly string $documentName,
        public readonly string $description,
        public readonly bool $mandatory,
        public readonly string $acceptedFileTypes,
        public readonly int $maxSizeBytes,
        public readonly string $singleOrMultiple,
        public readonly bool $reuseAllowed,
        public readonly ?string $reviewerInstructions,
        public readonly int $sortOrder,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
