<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Domain\Courses\LearningMediaPolicy;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;

/**
 * Learning-media store. Separate binding from credential-document ObjectStorage.
 * Signed URLs expire in 10–15 minutes and are not persisted.
 */
final class LearningMediaStorage implements ObjectStorage
{
    public function __construct(
        private readonly ObjectStorage $inner,
        private readonly LearningMediaPolicy $policy = new LearningMediaPolicy(),
    ) {
    }

    public function issueUploadAuthorization(
        string $objectKey,
        string $mimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
    ): array {
        $this->policy->assertObjectKey($objectKey);

        return $this->inner->issueUploadAuthorization($objectKey, $mimeType, $maxSizeBytes, $expiresAt);
    }

    public function objectExists(string $objectKey): ?ObjectMetadata
    {
        $this->policy->assertObjectKey($objectKey);

        return $this->inner->objectExists($objectKey);
    }

    public function issueDownloadUrl(string $objectKey, DateTimeImmutable $expiresAt): array
    {
        $this->policy->assertObjectKey($objectKey);
        $seconds = $expiresAt->getTimestamp() - time();
        if ($seconds < 600 || $seconds > 900) {
            throw new ValidationException('Learning media URLs must expire in 10 to 15 minutes.');
        }

        return $this->inner->issueDownloadUrl($objectKey, $expiresAt);
    }

    public function putObject(string $objectKey, string $contents, string $mimeType): ObjectMetadata
    {
        $this->policy->assertObjectKey($objectKey);

        return $this->inner->putObject($objectKey, $contents, $mimeType);
    }

    public function deleteObject(string $objectKey): void
    {
        $this->policy->assertObjectKey($objectKey);
        $this->inner->deleteObject($objectKey);
    }
}
