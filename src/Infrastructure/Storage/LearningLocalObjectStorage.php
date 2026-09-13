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
 * Private learning-media store. Allowed in every environment when
 * LEARNING_STORAGE_DRIVER=local. Not used for credential documents.
 * Files never live under public/.
 */
final class LearningLocalObjectStorage implements ObjectStorage
{
    public function __construct(
        private readonly string $rootDirectory,
        private readonly string $signingSecret,
    ) {
        if ($this->signingSecret === '') {
            throw new ServiceUnavailableException('Learning media signing secret is not configured.');
        }
        if ($this->isInsidePublicWebRoot($this->rootDirectory)) {
            throw new ServiceUnavailableException('Learning media must not be stored in a public web folder.');
        }
        if (!is_dir($this->rootDirectory) && !mkdir($this->rootDirectory, 0770, true) && !is_dir($this->rootDirectory)) {
            throw new RuntimeException('Unable to create learning media storage directory.');
        }
    }

    public function issueUploadAuthorization(
        string $objectKey,
        string $mimeType,
        int $maxSizeBytes,
        DateTimeImmutable $expiresAt,
    ): array {
        $this->assertSafeKey($objectKey);

        return [
            'upload_url' => '/__local-storage/learning/upload?key=' . rawurlencode($objectKey),
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

        return new ObjectMetadata((int) $size, null, hash_file('sha256', $path) ?: null);
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
            'download_url' => '/__local-storage/learning/download?key='
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
            throw new RuntimeException('Unable to create learning media directory.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write learning media.');
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
            throw new RuntimeException('Unable to read learning media.');
        }

        return $contents;
    }

    private function sign(string $objectKey, int $exp): string
    {
        return hash_hmac('sha256', 'learning|' . $objectKey . '|' . $exp, $this->signingSecret);
    }

    private function pathFor(string $objectKey): string
    {
        $this->assertSafeKey($objectKey);

        return $this->rootDirectory . '/' . $objectKey;
    }

    private function assertSafeKey(string $objectKey): void
    {
        if ($objectKey === ''
            || str_contains($objectKey, '..')
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, "\0")
            || !str_starts_with($objectKey, 'learning/media/')
        ) {
            throw new ValidationException('Invalid object key.', ['object_key' => ['Learning media key prefix is invalid.']]);
        }
    }

    private function isInsidePublicWebRoot(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/public/')
            || str_ends_with($normalized, '/public');
    }
}
