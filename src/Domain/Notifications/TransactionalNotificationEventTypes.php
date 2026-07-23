<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

/**
 * Domain outbox event types consumed by the transactional notification worker (WP-07).
 * These are existing business events — not new notification-specific outbox types.
 */
final class TransactionalNotificationEventTypes
{
    public const APPLICATION_SUBMITTED = 'application.submitted';
    public const APPLICATION_CORRECTION_REQUESTED = 'application.correction_requested';
    public const APPLICATION_CORRECTIONS_RESUBMITTED = 'application.corrections_resubmitted';
    public const APPLICATION_APPROVED = 'application.approved';
    public const APPLICATION_REJECTED = 'application.rejected';
    public const PAYMENT_FAILED = 'payment.failed';
    public const PAYMENT_RECONCILIATION_REQUIRED = 'payment.reconciliation_required';
    public const PAYMENT_SUCCESSFUL = 'payment.successful';
    public const APPLICATION_ADMITTED = 'application.admitted';
    public const ENROLMENT_CREATED = 'enrolment.created';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::APPLICATION_SUBMITTED,
            self::APPLICATION_CORRECTION_REQUESTED,
            self::APPLICATION_CORRECTIONS_RESUBMITTED,
            self::APPLICATION_APPROVED,
            self::APPLICATION_REJECTED,
            self::PAYMENT_FAILED,
            self::PAYMENT_RECONCILIATION_REQUIRED,
            self::PAYMENT_SUCCESSFUL,
            self::APPLICATION_ADMITTED,
            self::ENROLMENT_CREATED,
        ];
    }

    public static function isTransactional(string $eventType): bool
    {
        return in_array($eventType, self::all(), true);
    }
}
