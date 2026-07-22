<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use DateTimeImmutable;

final class Application
{
    public function __construct(
        public readonly int $applicationId,
        public readonly string $applicationNumber,
        public readonly int $userId,
        public readonly int $courseVersionId,
        public readonly int $batchId,
        public readonly string $status,
        public readonly int $stateVersion,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly ?string $declarationAcceptedVersion,
        public readonly ?DateTimeImmutable $declarationAcceptedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isDraft(): bool
    {
        return $this->status === ApplicationStatus::DRAFT;
    }

    public function isEditableByLearner(): bool
    {
        return $this->isDraft();
    }
}
