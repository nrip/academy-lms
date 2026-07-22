<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateTimeImmutable;

interface DocumentUploadAuthorizationRepository
{
    public function insert(
        int $applicationId,
        int $requirementId,
        int $userId,
        string $objectKey,
        string $displayFilename,
        string $declaredMimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): DocumentUploadAuthorization;

    public function findByObjectKeyForUpdate(string $objectKey): ?DocumentUploadAuthorization;

    /**
     * Used by the local-storage upload path (LocalUploadController), which
     * only knows the authorizationId embedded in its URL, not the object_key.
     */
    public function findByIdForUpdate(int $authorizationId): ?DocumentUploadAuthorization;

    public function markConsumed(int $authorizationId, DateTimeImmutable $now): bool;
}
