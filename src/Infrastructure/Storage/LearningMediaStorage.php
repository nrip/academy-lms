<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Domain\Courses\LearningMediaPolicy;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;
use DateTimeZone;

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

    /**
     * Reads a private learning object. Local storage is read from disk.
     * S3 is read by fetching the short-lived signed URL this class just issued.
     */
    public function readObject(string $objectKey): string
    {
        $this->policy->assertObjectKey($objectKey);
        if ($this->inner instanceof LearningLocalObjectStorage) {
            return $this->inner->readObject($objectKey);
        }

        $expiresAt = (new DateTimeImmutable('@' . (time() + 900)))->setTimezone(new DateTimeZone('UTC'));
        $issued = $this->issueDownloadUrl($objectKey, $expiresAt);

        return $this->fetchSignedUrl($issued['download_url']);
    }

    private function fetchSignedUrl(string $url): string
    {
        if (!str_starts_with($url, 'https://') || !function_exists('curl_init')) {
            throw new ExternalServiceException('Learning media could not be read.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ExternalServiceException('Learning media could not be read.');
        }
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new ExternalServiceException('Learning media could not be read.');
        }

        return $body;
    }
}
