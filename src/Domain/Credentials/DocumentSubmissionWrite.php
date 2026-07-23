<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateTimeImmutable;

final class DocumentSubmissionWrite
{
    public function __construct(
        public readonly int $applicationId,
        public readonly int $requirementId,
        public readonly string $objectKey,
        public readonly string $displayFilename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $checksumSha256,
        public readonly string $status,
        public readonly string $scanStatus,
        public readonly int $uploadedByUserId,
        public readonly DateTimeImmutable $submittedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $scanQueuedAt = null,
        public readonly ?string $learnerVisibleMessage = null,
        public readonly ?int $reviewedByUserId = null,
        public readonly ?DateTimeImmutable $reviewedAt = null,
    ) {
    }
}
