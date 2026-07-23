<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

final class ReviewerScopeAssignment
{
    public function __construct(
        public readonly int $scopeAssignmentId,
        public readonly int $reviewerUserId,
        public readonly string $scopeType,
        public readonly ?int $courseId,
        public readonly ?int $courseVersionId,
        public readonly ?int $batchId,
        public readonly bool $includeFutureVersions,
        public readonly DateTimeImmutable $effectiveFrom,
        public readonly ?DateTimeImmutable $effectiveTo,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly ?string $revokedReason,
        public readonly int $createdByUserId,
        public readonly ?int $revokedByUserId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        if ($at < $this->effectiveFrom) {
            return false;
        }

        if ($this->effectiveTo !== null && $at > $this->effectiveTo) {
            return false;
        }

        return true;
    }
}
