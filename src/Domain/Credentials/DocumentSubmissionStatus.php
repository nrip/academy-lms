<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

/**
 * REQ-DOC-2 business statuses (persisted rows never use "not_uploaded").
 */
final class DocumentSubmissionStatus
{
    public const UPLOADED = 'uploaded';
    public const UNDER_REVIEW = 'under_review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const RESUBMISSION_REQUESTED = 'resubmission_requested';
    public const EXPIRED = 'expired';
    public const SUPERSEDED = 'superseded';
    public const FAILED_SECURITY_SCAN = 'failed_security_scan';

    /** @var list<string> */
    public const ALL = [
        self::UPLOADED,
        self::UNDER_REVIEW,
        self::APPROVED,
        self::REJECTED,
        self::RESUBMISSION_REQUESTED,
        self::EXPIRED,
        self::SUPERSEDED,
        self::FAILED_SECURITY_SCAN,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid document submission status.');
        }
    }
}
