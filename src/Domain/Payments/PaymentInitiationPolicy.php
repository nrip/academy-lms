<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;

/**
 * Learner may initiate only when Application is payment_pending and no
 * in-flight/settled attempt blocks a new row (Q1 in-flight gate + PAY-ATTEMPT-1).
 */
final class PaymentInitiationPolicy
{
    /**
     * @param list<Payment> $existingAttempts Ordered by attempt_number ASC
     */
    public function assertCanInitiate(
        Application $application,
        int $actorUserId,
        array $existingAttempts,
    ): void {
        if ($application->userId !== $actorUserId) {
            throw new NotFoundException('Application not found.');
        }

        if ($application->status !== ApplicationStatus::PAYMENT_PENDING) {
            throw new ConflictException('Payment can only be initiated when the application is payment pending.');
        }

        foreach ($existingAttempts as $attempt) {
            if ($attempt->applicationId !== $application->applicationId) {
                throw new DomainRuleException('Payment attempt does not belong to this application.');
            }

            if (PaymentStatus::isInFlight($attempt->status)) {
                throw new ConflictException(
                    'A payment attempt is already in progress. Wait for it to finish or expire before retrying.',
                );
            }

            if ($attempt->status === PaymentStatus::SUCCESSFUL
                || $attempt->status === PaymentStatus::RECONCILIATION_PENDING
            ) {
                throw new ConflictException(
                    'This application already has an accepted or reconciliation payment outcome.',
                );
            }

            if (PaymentStatus::blocksNewAttempt($attempt->status)
                && !PaymentStatus::isRetryEligible($attempt->status)
            ) {
                throw new ConflictException(
                    'A new payment attempt is not allowed while a prior attempt is in status ' . $attempt->status . '.',
                );
            }
        }
    }

    /**
     * @param list<Payment> $existingAttempts
     */
    public function nextAttemptNumber(array $existingAttempts): int
    {
        $max = 0;
        foreach ($existingAttempts as $attempt) {
            $max = max($max, $attempt->attemptNumber);
        }

        return $max + 1;
    }
}
