<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use DateTimeImmutable;

/**
 * Sanitized verification history row for R-02 (no object keys).
 */
final class ReviewerVerificationHistoryItem
{
    public function __construct(
        public readonly int $verificationAuditId,
        public readonly string $action,
        public readonly ?int $documentSubmissionId,
        public readonly ?int $requirementId,
        public readonly int $reviewerUserId,
        public readonly ?string $statusBefore,
        public readonly ?string $statusAfter,
        public readonly ?string $reasonCode,
        public readonly ?string $learnerVisibleMessage,
        public readonly ?string $internalNote,
        public readonly ?int $stateVersion,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
