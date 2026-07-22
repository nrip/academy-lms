<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class CourseVersion
{
    /**
     * @param array<string, mixed>|null $faq
     */
    public function __construct(
        public readonly int $versionId,
        public readonly int $courseId,
        public readonly int $versionNumber,
        public readonly string $title,
        public readonly string $description,
        public readonly string $learningObjectives,
        public readonly string $intendedAudience,
        public readonly string $syllabusSummary,
        public readonly string $admissionMode,
        public readonly string $deliveryType,
        public readonly string $durationText,
        public readonly ?int $validityPeriodDays,
        public readonly string $standardFee,
        public readonly string $gstRate,
        public readonly string $currency,
        public readonly string $certificateType,
        public readonly ?array $faq,
        public readonly string $status,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly ?DateTimeImmutable $lockedAt,
        public readonly ?string $lockedReason,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isLocked(): bool
    {
        return $this->lockedAt !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === CourseVersionStatus::PUBLISHED;
    }
}
