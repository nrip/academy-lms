<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

/**
 * The 11 SRS §18 / AGENTS.md §6.1 Application states. WP-02 only ever
 * produces DRAFT (via ApplicationDraftFactory, not a state machine).
 * ApplicationStateMachine (submit onward) is out of scope for this slice.
 */
final class ApplicationStatus
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const DOCUMENTS_INCOMPLETE = 'documents_incomplete';
    public const UNDER_REVIEW = 'under_review';
    public const RESUBMISSION_REQUESTED = 'resubmission_requested';
    public const PAYMENT_PENDING = 'payment_pending';
    public const AWAITING_VERIFICATION = 'awaiting_verification';
    public const ADMITTED = 'admitted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT,
        self::SUBMITTED,
        self::DOCUMENTS_INCOMPLETE,
        self::UNDER_REVIEW,
        self::RESUBMISSION_REQUESTED,
        self::PAYMENT_PENDING,
        self::AWAITING_VERIFICATION,
        self::ADMITTED,
        self::REJECTED,
        self::WITHDRAWN,
        self::EXPIRED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid application status.');
        }
    }
}
