<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

/**
 * Ingest rules for learning media. No transcoding: the file must already be playable.
 *
 * Byte cap defaults to the platform downloadable-resource cap (100 MB). A larger
 * lecture-video cap is not set here until product confirms a number.
 */
final class LearningMediaPolicy
{
    public const PREFIX = 'learning/media/';
    public const PLATFORM_RESOURCE_CAP_BYTES = 104857600;

    public function __construct(
        private readonly int $maxBytes = self::PLATFORM_RESOURCE_CAP_BYTES,
    ) {
    }

    public function assertObjectKey(string $objectKey): string
    {
        if ($objectKey === ''
            || str_contains($objectKey, '..')
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, "\0")
            || str_contains($objectKey, '\\')
            || !str_starts_with($objectKey, self::PREFIX)
        ) {
            throw new ValidationException('Learning media object key is invalid.');
        }

        return $objectKey;
    }

    public function assertPlayable(string $kind, string $bytes): string
    {
        if ($bytes === '') {
            throw new ValidationException('The file is empty.');
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new ValidationException(
                'The file exceeds the current learning-media size cap. It must already be in a playable format, and the cap has not been raised for lecture video.',
            );
        }

        $mime = $this->detectMime($bytes);
        $allowed = match ($kind) {
            'pdf' => ['application/pdf'],
            'video' => ['video/mp4', 'video/webm'],
            'audio' => ['audio/mpeg', 'audio/mp4', 'audio/wav'],
            default => throw new ValidationException('This file type cannot be stored as learning media.'),
        };
        if (!in_array($mime, $allowed, true)) {
            throw new ValidationException(
                'The file must already be in a playable format. Transcoding is not available.',
            );
        }

        return $mime;
    }

    public function displayFilename(?string $originalFilename): ?string
    {
        if ($originalFilename === null || trim($originalFilename) === '') {
            return null;
        }
        $base = basename(str_replace('\\', '/', $originalFilename));
        if ($base === '' || $base === '.' || $base === '..' || str_contains($base, "\0")) {
            throw new ValidationException('Original filename is invalid.');
        }
        if (mb_strlen($base) > 255) {
            throw new ValidationException('Original filename must be 255 characters or fewer.');
        }

        return $base;
    }

    private function detectMime(string $bytes): string
    {
        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
            return 'video/webm';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }
        if (str_starts_with($bytes, 'ID3') || $this->looksLikeMp3Frame($bytes)) {
            return 'audio/mpeg';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if (in_array($brand, ['M4A ', 'M4B '], true)) {
                return 'audio/mp4';
            }
            if (in_array($brand, ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'M4V '], true)) {
                return 'video/mp4';
            }
        }

        throw new ValidationException(
            'The file must already be in a playable format. Transcoding is not available.',
        );
    }

    private function looksLikeMp3Frame(string $bytes): bool
    {
        if (strlen($bytes) < 2) {
            return false;
        }
        $first = ord($bytes[0]);
        $second = ord($bytes[1]);

        return $first === 0xFF && ($second & 0xE0) === 0xE0;
    }
}
