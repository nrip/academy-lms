<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

/**
 * Course cover images. Separate from lesson files and credential documents.
 * The byte cap is configuration; the default matches the approved 5 MB profile-image cap.
 */
final class CourseCoverPolicy
{
    public const PREFIX = 'learning/catalogue/';

    public const DEFAULT_MAX_BYTES = 5242880;

    /** @var list<string> */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        if ($this->maxBytes < 1) {
            throw new ValidationException('Course image upload limit is not configured.');
        }
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function limitMegabytes(): string
    {
        $mb = $this->maxBytes / 1048576;
        if (abs($mb - round($mb)) < 0.05) {
            return (string) (int) round($mb);
        }

        return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');
    }

    public function uploadLimitMessage(): string
    {
        return 'This image is larger than the ' . $this->limitMegabytes() . ' MB upload limit.';
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
            throw new ValidationException('Course image could not be stored.');
        }

        return $objectKey;
    }

    /**
     * @return array{mime: string, bytes: int}
     */
    public function assertImage(string $bytes): array
    {
        if ($bytes === '') {
            throw new ValidationException('Choose an image to upload.');
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new ValidationException($this->uploadLimitMessage());
        }

        $mime = $this->detectMime($bytes);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException('Use a JPG, PNG, or WebP image.');
        }

        return ['mime' => $mime, 'bytes' => strlen($bytes)];
    }

    public function displayFilename(?string $originalFilename): ?string
    {
        if ($originalFilename === null || trim($originalFilename) === '') {
            return null;
        }
        $base = basename(str_replace('\\', '/', $originalFilename));
        if ($base === '' || $base === '.' || $base === '..' || str_contains($base, "\0")) {
            throw new ValidationException('Image filename is invalid.');
        }
        if (mb_strlen($base) > 255) {
            throw new ValidationException('Image filename is too long.');
        }

        return $base;
    }

    private function detectMime(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        throw new ValidationException('Use a JPG, PNG, or WebP image.');
    }
}
