<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Storage;

use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Domain\Storage\ObjectStorage;
use DateTimeImmutable;
use RuntimeException;

/**
 * Private local object store for local/testing/ci only.
 */
final class LocalObjectStorage implements ObjectStorage
{
    public function __construct(
        private readonly string $rootDirectory,
        private readonly string $signingSecret,
        private readonly string $env,
    ) {
        if (!in_array($this->env, ['local', 'testing', 'ci'], true)) {
            throw new ServiceUnavailableException('Local object storage is not permitted in this environment.');
        }
        if ($this->signingSecret === '') {
            throw new ServiceUnavailableException('Local object storage signing secret is not configured.');
        }
        if (!is_dir($this->rootDirectory) && !mkdir($this->rootDirectory, 0770, true) && !is_dir($this->rootDirectory)) {
            throw new RuntimeException('Unable to create local object storage directory.');
        }
    }

    public function issueUploadAuthorization(
        string $objectKey,
        string $mimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
    ): array {
        $this->assertSafeKey($objectKey);

        // Placeholder URL — DocumentUploadService overrides with the local-upload route.
        return [
            'upload_url' => '/__local-storage/upload?key=' . rawurlencode($objectKey),
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => $mimeType,
                'X-Max-Size' => (string) $maxSizeBytes,
            ],
            'expires_at' => $expiresAt,
        ];
    }

    public function objectExists(string $objectKey): ?ObjectMetadata
    {
        $path = $this->pathFor($objectKey);
        if (!is_file($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false) {
            return null;
        }

        $mime = mime_content_type($path) ?: null;
        $checksum = hash_file('sha256', $path) ?: null;

        return new ObjectMetadata((int) $size, $mime, $checksum);
    }

    public function issueDownloadUrl(string $objectKey, DateTimeImmutable $expiresAt): array
    {
        $this->assertSafeKey($objectKey);
        if ($this->objectExists($objectKey) === null) {
            throw new NotFoundException('Object not found.');
        }

        $exp = $expiresAt->getTimestamp();
        $sig = $this->sign($objectKey, $exp);

        return [
            'download_url' => '/__local-storage/documents/download?key='
                . rawurlencode($objectKey)
                . '&exp=' . $exp
                . '&sig=' . $sig,
            'expires_at' => $expiresAt,
        ];
    }

    public function putObject(string $objectKey, string $contents, string $mimeType): ObjectMetadata
    {
        $this->assertSafeKey($objectKey);
        $path = $this->pathFor($objectKey);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create object directory.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write object.');
        }

        return new ObjectMetadata(strlen($contents), $mimeType, hash('sha256', $contents));
    }

    public function deleteObject(string $objectKey): void
    {
        $path = $this->pathFor($objectKey);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function verifySignedUrl(string $objectKey, int $exp, string $sig): bool
    {
        if ($exp < time()) {
            return false;
        }

        return hash_equals($this->sign($objectKey, $exp), $sig);
    }

    public function readObject(string $objectKey): string
    {
        $path = $this->pathFor($objectKey);
        if (!is_file($path)) {
            throw new NotFoundException('Object not found.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read object.');
        }

        return $contents;
    }

    private function sign(string $objectKey, int $exp): string
    {
        return hash_hmac('sha256', $objectKey . '|' . $exp, $this->signingSecret);
    }

    private function pathFor(string $objectKey): string
    {
        $this->assertSafeKey($objectKey);

        return $this->rootDirectory . '/' . $objectKey;
    }

    private function assertSafeKey(string $objectKey): void
    {
        if ($objectKey === '' || str_contains($objectKey, '..') || str_starts_with($objectKey, '/')
            || str_contains($objectKey, "\0")
        ) {
            throw new ValidationException('Invalid object key.', ['object_key' => ['Invalid object key.']]);
        }
        if (!str_starts_with($objectKey, 'applications/')) {
            throw new ValidationException('Invalid object key.', ['object_key' => ['Object key prefix is invalid.']]);
        }
    }
}
