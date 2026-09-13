<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class Course
{
    public function __construct(
        public readonly int $courseId,
        public readonly string $courseCode,
        public readonly string $slug,
        public readonly string $masterTitle,
        public readonly string $status,
        public readonly ?int $currentPublishedVersionId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?string $coverObjectKey = null,
        public readonly ?string $coverFilename = null,
        public readonly ?string $coverMime = null,
        public readonly ?int $coverBytes = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === CourseStatus::ACTIVE;
    }

    public function hasCover(): bool
    {
        return $this->coverObjectKey !== null && $this->coverObjectKey !== '';
    }
}
