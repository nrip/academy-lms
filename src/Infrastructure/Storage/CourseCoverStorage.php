<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Domain\Courses\CourseCoverPolicy;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Private course-cover store. Same driver as learning media, different key prefix.
 * Signed URLs are not persisted and are used only to read an object on S3.
 */
final class CourseCoverStorage
{
    public function __construct(
        private readonly ObjectStorage $inner,
        private readonly CourseCoverPolicy $policy,
    ) {
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

    public function readObject(string $objectKey): string
    {
        $this->policy->assertObjectKey($objectKey);
        if ($this->inner instanceof LearningLocalObjectStorage) {
            return $this->inner->readObject($objectKey);
        }

        $expiresAt = (new DateTimeImmutable('@' . (time() + 900)))->setTimezone(new DateTimeZone('UTC'));
        $issued = $this->inner->issueDownloadUrl($objectKey, $expiresAt);
        $url = $issued['download_url'] ?? '';
        if (!is_string($url) || !str_starts_with($url, 'https://') || !function_exists('curl_init')) {
            throw new ExternalServiceException('Course image could not be read.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ExternalServiceException('Course image could not be read.');
        }
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new NotFoundException('Course image not found.');
        }

        return $body;
    }
}
