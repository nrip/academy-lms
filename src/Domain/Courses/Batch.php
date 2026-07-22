<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

final class Batch
{
    public function __construct(
        public readonly int $batchId,
        public readonly int $courseVersionId,
        public readonly string $batchCode,
        public readonly string $name,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly DateTimeImmutable $applicationsOpenAt,
        public readonly DateTimeImmutable $applicationsCloseAt,
        public readonly int $minCapacity,
        public readonly int $maxCapacity,
        public readonly string $deliveryMode,
        public readonly string $venueOrOnlineDetails,
        public readonly string $timezone,
        public readonly ?string $feeOverride,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?DateTimeImmutable $accessExpiresAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function effectiveFee(CourseVersion $version): string
    {
        return $this->feeOverride ?? $version->standardFee;
    }
}
