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
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === CourseStatus::ACTIVE;
    }
}
