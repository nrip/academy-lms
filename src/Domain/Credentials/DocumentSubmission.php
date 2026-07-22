<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateTimeImmutable;

final class DocumentSubmission
{
    public function __construct(
        public readonly int $documentSubmissionId,
        public readonly int $applicationId,
        public readonly int $requirementId,
        public readonly string $objectKey,
        public readonly string $displayFilename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $checksumSha256,
        public readonly string $status,
        public readonly string $scanStatus,
        public readonly ?string $rejectionReasonCode,
        public readonly int $uploadedByUserId,
        public readonly DateTimeImmutable $submittedAt,
        public readonly ?DateTimeImmutable $supersededAt,
        public readonly ?int $currentMarker,
        public readonly int $rowVersion,
        public readonly int $scanAttemptCount,
        public readonly ?DateTimeImmutable $scanQueuedAt,
        public readonly ?DateTimeImmutable $scanCompletedAt,
        public readonly ?string $scanLeaseOwner,
        public readonly ?string $scanLeaseToken,
        public readonly ?DateTimeImmutable $scanLeaseExpiresAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function isCurrent(): bool
    {
        return $this->currentMarker === 1;
    }

    public function isAcceptableForSubmission(): bool
    {
        if (!$this->isCurrent()) {
            return false;
        }
        if ($this->scanStatus !== DocumentScanStatus::CLEAN) {
            return false;
        }

        return in_array($this->status, [
            DocumentSubmissionStatus::UPLOADED,
            DocumentSubmissionStatus::UNDER_REVIEW,
            DocumentSubmissionStatus::APPROVED,
        ], true);
    }
}
