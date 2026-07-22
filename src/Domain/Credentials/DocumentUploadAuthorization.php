<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateTimeImmutable;

final class DocumentUploadAuthorization
{
    public function __construct(
        public readonly int $authorizationId,
        public readonly int $applicationId,
        public readonly int $requirementId,
        public readonly int $userId,
        public readonly string $objectKey,
        public readonly string $displayFilename,
        public readonly string $declaredMimeType,
        public readonly int $maxSizeBytes,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $consumedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }
}
