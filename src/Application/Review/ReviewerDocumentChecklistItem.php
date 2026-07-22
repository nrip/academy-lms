<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use DateTimeImmutable;

/**
 * Document checklist row for R-02 — no raw object keys.
 */
final class ReviewerDocumentChecklistItem
{
    public function __construct(
        public readonly int $requirementId,
        public readonly string $documentName,
        public readonly bool $mandatory,
        public readonly ?int $documentSubmissionId,
        public readonly ?string $displayFilename,
        public readonly ?string $status,
        public readonly ?string $scanStatus,
        public readonly ?string $rejectionReasonCode,
        public readonly ?string $learnerVisibleMessage,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly ?DateTimeImmutable $reviewedAt,
    ) {
    }
}
