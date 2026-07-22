<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;

final class UnconfiguredObjectStorage implements ObjectStorage
{
    public function issueUploadAuthorization(
        string $objectKey,
        string $mimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
    ): array {
        throw new ServiceUnavailableException('Object storage is not configured.');
    }

    public function objectExists(string $objectKey): ?ObjectMetadata
    {
        throw new ServiceUnavailableException('Object storage is not configured.');
    }

    public function issueDownloadUrl(string $objectKey, DateTimeImmutable $expiresAt): array
    {
        throw new ServiceUnavailableException('Object storage is not configured.');
    }

    public function putObject(string $objectKey, string $contents, string $mimeType): ObjectMetadata
    {
        throw new ServiceUnavailableException('Object storage is not configured.');
    }

    public function deleteObject(string $objectKey): void
    {
        throw new ServiceUnavailableException('Object storage is not configured.');
    }
}
