<?php

declare(strict_types=1);

namespace Academy\Domain\Storage;

use DateTimeImmutable;

interface ObjectStorage
{
    /**
     * @return array{upload_url: string, method: string, headers: array<string, string>, expires_at: DateTimeImmutable}
     */
    public function issueUploadAuthorization(
        string $objectKey,
        string $mimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
    ): array;

    public function objectExists(string $objectKey): ?ObjectMetadata;

    /**
     * @return array{download_url: string, expires_at: DateTimeImmutable}
     */
    public function issueDownloadUrl(string $objectKey, DateTimeImmutable $expiresAt): array;

    public function putObject(string $objectKey, string $contents, string $mimeType): ObjectMetadata;

    public function deleteObject(string $objectKey): void;
}
