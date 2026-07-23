<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

/**
 * AGENTS.md §6.3 / SRS REQ-PAY-7 — exactly 10 Payment statuses.
 * No authorized/captured/processing domain statuses.
 */
final class PaymentStatus
{
    public const CREATED = 'created';
    public const PENDING = 'pending';
    public const SUCCESSFUL = 'successful';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const RECONCILIATION_PENDING = 'reconciliation_pending';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const DISPUTED = 'disputed';

    /** @var list<string> */
    public const ALL = [
        self::CREATED,
        self::PENDING,
        self::SUCCESSFUL,
        self::FAILED,
        self::CANCELLED,
        self::EXPIRED,
        self::RECONCILIATION_PENDING,
        self::REFUNDED,
        self::PARTIALLY_REFUNDED,
        self::DISPUTED,
    ];

    /** Statuses that block a new payment attempt (in-flight or settled). */
    /** @var list<string> */
    public const BLOCK_NEW_ATTEMPT = [
        self::CREATED,
        self::PENDING,
        self::SUCCESSFUL,
        self::RECONCILIATION_PENDING,
        self::REFUNDED,
        self::PARTIALLY_REFUNDED,
        self::DISPUTED,
    ];

    /** Prior attempt must be one of these before a retry is allowed. */
    /** @var list<string> */
    public const RETRY_ELIGIBLE = [
        self::FAILED,
        self::CANCELLED,
        self::EXPIRED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid payment status.');
        }
    }

    public static function isInFlight(string $status): bool
    {
        return $status === self::CREATED || $status === self::PENDING;
    }

    public static function blocksNewAttempt(string $status): bool
    {
        return in_array($status, self::BLOCK_NEW_ATTEMPT, true);
    }

    public static function isRetryEligible(string $status): bool
    {
        return in_array($status, self::RETRY_ELIGIBLE, true);
    }
}
