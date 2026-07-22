<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Exception\ValidationException;

final class DocumentFileValidator
{
    private const PLATFORM_MAX_BYTES = 10485760;

    /** @var list<string> */
    private const DENIED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'js', 'jar', 'sh', 'php', 'phtml',
        'svg', 'html', 'htm', 'xhtml', 'xml',
    ];

    /**
     * Validate declared MIME, size and filename against a CourseDocumentRequirement.
     */
    public function assertAllowed(
        CourseDocumentRequirement $requirement,
        string $declaredMime,
        int $sizeBytes,
        string $originalFilename,
    ): string {
        if ($sizeBytes <= 0) {
            throw new ValidationException('Please correct the highlighted fields.', ['size_bytes' => ['File size must be greater than zero.']]);
        }

        $max = min((int) $requirement->maxSizeBytes, self::PLATFORM_MAX_BYTES);
        if ($sizeBytes > $max) {
            throw new ValidationException('Please correct the highlighted fields.', ['size_bytes' => ['File exceeds the maximum allowed size.']]);
        }

        $sanitized = $this->sanitizeFilename($originalFilename);
        $extension = strtolower(pathinfo($sanitized, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::DENIED_EXTENSIONS, true)) {
            throw new ValidationException('Please correct the highlighted fields.', ['filename' => ['File type is not allowed.']]);
        }

        $accepted = $this->parseAcceptedTypes($requirement->acceptedFileTypes);
        $mimeOk = in_array(strtolower($declaredMime), $accepted['mimes'], true);
        $extOk = in_array($extension, $accepted['extensions'], true);
        if (!$mimeOk && !$extOk) {
            throw new ValidationException('Please correct the highlighted fields.', ['mime_type' => ['File type is not accepted for this requirement.']]);
        }

        if (!$this->mimeMatchesExtension($declaredMime, $extension)) {
            throw new ValidationException('Please correct the highlighted fields.', ['mime_type' => ['Declared MIME type does not match the file extension.']]);
        }

        return $sanitized;
    }

    public function sanitizeFilename(string $originalFilename): string
    {
        $base = basename(str_replace(["\0", '\\'], '', $originalFilename));
        $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? 'document';
        $base = trim($base, '._ ');
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'document';
        }
        if (strlen($base) > 180) {
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            $base = substr($base, 0, 160) . ($ext !== '' ? '.' . $ext : '');
        }

        return $base;
    }

    /**
     * @return array{mimes: list<string>, extensions: list<string>}
     */
    private function parseAcceptedTypes(string $acceptedFileTypes): array
    {
        $tokens = preg_split('/[\s,;]+/', strtolower($acceptedFileTypes)) ?: [];
        $mimes = [];
        $extensions = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (str_contains($token, '/')) {
                $mimes[] = $token;
                continue;
            }
            $extensions[] = ltrim($token, '.');
        }

        // Map common extensions to MIME allow-list expansion.
        foreach ($extensions as $ext) {
            $mapped = match ($ext) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => null,
            };
            if ($mapped !== null) {
                $mimes[] = $mapped;
            }
        }

        return [
            'mimes' => array_values(array_unique($mimes)),
            'extensions' => array_values(array_unique($extensions)),
        ];
    }

    private function mimeMatchesExtension(string $mime, string $extension): bool
    {
        $mime = strtolower($mime);

        return match ($extension) {
            'pdf' => $mime === 'application/pdf',
            'jpg', 'jpeg' => $mime === 'image/jpeg',
            'png' => $mime === 'image/png',
            'webp' => $mime === 'image/webp',
            default => false,
        };
    }
}
