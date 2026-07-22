<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use DateTimeImmutable;

final class Application
{
    public function __construct(
        public readonly int $applicationId,
        public readonly int $userId,
        public readonly int $courseVersionId,
        public readonly int $batchId,
        public readonly string $status,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isDraft(): bool
    {
        return $this->status === ApplicationStatus::DRAFT;
    }
}
