<?php

declare(strict_types=1);

namespace Academy\Application\Credentials;

use Academy\Domain\Credentials\DocumentFileValidator;
use RuntimeException;

/**
 * Compares effective PHP upload INI limits to the application document maximum.
 */
final class PhpUploadRuntimeGuard
{
    /** Preferred post_max_size headroom above the application file cap (multipart overhead). */
    public const RECOMMENDED_POST_MAX_BYTES = 16 * 1024 * 1024;

    /**
     * @return array{
     *   app_max_bytes: int,
     *   upload_max_filesize: string,
     *   upload_max_bytes: int,
     *   post_max_size: string,
     *   post_max_bytes: int,
     *   adequate: bool,
     *   problems: list<string>
     * }
     */
    public static function inspect(?int $appMaxBytes = null): array
    {
        $appMax = $appMaxBytes ?? DocumentFileValidator::PLATFORM_MAX_BYTES;
        $uploadRaw = (string) ini_get('upload_max_filesize');
        $postRaw = (string) ini_get('post_max_size');
        $uploadBytes = self::parseIniSize($uploadRaw);
        $postBytes = self::parseIniSize($postRaw);

        $problems = [];
        if ($uploadBytes < $appMax) {
            $problems[] = sprintf(
                'PHP upload_max_filesize=%s (%d bytes) is below the application document limit of %d bytes (%s MB).',
                $uploadRaw !== '' ? $uploadRaw : '(empty)',
                $uploadBytes,
                $appMax,
                (string) (int) ($appMax / 1048576),
            );
        }
        // post_max must exceed the file cap so multipart fields + file fit.
        if ($postBytes < $appMax) {
            $problems[] = sprintf(
                'PHP post_max_size=%s (%d bytes) is below the application document limit of %d bytes.',
                $postRaw !== '' ? $postRaw : '(empty)',
                $postBytes,
                $appMax,
            );
        }

        return [
            'app_max_bytes' => $appMax,
            'upload_max_filesize' => $uploadRaw,
            'upload_max_bytes' => $uploadBytes,
            'post_max_size' => $postRaw,
            'post_max_bytes' => $postBytes,
            'adequate' => $problems === [],
            'problems' => $problems,
        ];
    }

    /**
     * @throws RuntimeException when PHP cannot accept files up to the application limit
     */
    public static function assertAdequateForApplication(?int $appMaxBytes = null): void
    {
        $report = self::inspect($appMaxBytes);
        if ($report['adequate']) {
            return;
        }

        $hint = 'Start the demo server with: composer demo-serve'
            . ' (or: php -d upload_max_filesize=10M -d post_max_size=16M -S 127.0.0.1:8080 -t public)';

        throw new RuntimeException(
            "PHP upload runtime is below the application document limit.\n"
            . implode("\n", $report['problems'])
            . "\n" . $hint,
        );
    }

    public static function formatMb(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0';
        }

        $mb = $bytes / 1048576;
        if (abs($mb - (int) $mb) < 0.05) {
            return (string) (int) $mb;
        }

        return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');
    }

    public static function parseIniSize(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0') {
            return 0;
        }

        if (!preg_match('/^(\d+)([KMG])?$/i', $trimmed, $matches)) {
            // Non-standard / unlimited markers — treat as zero (fail closed for guards).
            if (in_array(strtolower($trimmed), ['-1', 'unlimited'], true)) {
                return PHP_INT_MAX;
            }

            return 0;
        }

        $n = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
