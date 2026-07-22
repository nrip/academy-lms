<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

/**
 * Malware scan_status per STATE_MACHINE_ADDENDUM §2.2 (separate from business status).
 */
final class DocumentScanStatus
{
    public const NOT_APPLICABLE = 'not_applicable';
    public const PENDING = 'pending';
    public const CLEAN = 'clean';
    public const FAILED = 'failed';

    /** @var list<string> */
    public const ALL = [
        self::NOT_APPLICABLE,
        self::PENDING,
        self::CLEAN,
        self::FAILED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid document scan status.');
        }
    }

    public static function blocksSubmission(string $scanStatus): bool
    {
        return $scanStatus !== self::CLEAN;
    }
}
