<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Domain\Courses\LearningMediaPolicy;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Storage\ObjectMetadata;
use Academy\Infrastructure\Storage\LearningMediaStorage;

/**
 * Stores a learning-media blob under learning/media/. Does not touch credential documents.
 */
final class LearningMediaIngestService
{
    public function __construct(
        private readonly LearningMediaStorage $storage,
        private readonly LearningMediaPolicy $policy,
    ) {
    }

    /**
     * @return array{
     *   object_key: string,
     *   original_filename: ?string,
     *   media_mime: string,
     *   media_bytes: int,
     *   media_sha256: string
     * }
     */
    public function store(string $kind, string $bytes, ?string $originalFilename = null): array
    {
        $mime = $this->policy->assertPlayable($kind, $bytes);
        $filename = $this->policy->displayFilename($originalFilename);
        $objectKey = LearningMediaPolicy::PREFIX . bin2hex(random_bytes(16));
        $stored = $this->storage->putObject($objectKey, $bytes, $mime);
        if ($stored->checksumSha256 === null) {
            throw new ValidationException('Learning media checksum could not be recorded.');
        }

        return [
            'object_key' => $objectKey,
            'original_filename' => $filename,
            'media_mime' => $mime,
            'media_bytes' => $stored->sizeBytes,
            'media_sha256' => $stored->checksumSha256,
        ];
    }

    public function readMetadata(string $objectKey): ?ObjectMetadata
    {
        return $this->storage->objectExists($objectKey);
    }
}
