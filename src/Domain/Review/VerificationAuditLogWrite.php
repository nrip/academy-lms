<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use DateTimeImmutable;

final class VerificationAuditLogWrite
{
    public function __construct(
        public readonly int $applicationId,
        public readonly int $reviewerUserId,
        public readonly string $action,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $documentSubmissionId = null,
        public readonly ?int $requirementId = null,
        public readonly ?string $statusBefore = null,
        public readonly ?string $statusAfter = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $learnerVisibleMessage = null,
        public readonly ?string $internalNote = null,
        public readonly ?int $stateVersion = null,
        public readonly ?int $rowVersion = null,
    ) {
    }
}
