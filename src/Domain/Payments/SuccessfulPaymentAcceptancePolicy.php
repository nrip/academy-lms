<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Learning\Enrolment;

/**
 * Deterministic rules for accepting a captured Payment as the single successful outcome.
 */
final class SuccessfulPaymentAcceptancePolicy
{
    /**
     * @param list<Payment> $applicationPayments
     */
    public function assertCanAcceptSuccess(
        Application $application,
        Payment $candidate,
        GatewayPaymentResult $provider,
        array $applicationPayments,
        ?Enrolment $existingEnrolment,
        ?Payment $existingSuccessful,
    ): void {
        if (!$candidate->belongsToApplication($application->applicationId)) {
            throw new DomainRuleException('Payment does not belong to application.');
        }

        if ($application->status !== ApplicationStatus::PAYMENT_PENDING) {
            throw new ConflictException('Application is not payment_pending.');
        }

        if ($existingEnrolment !== null) {
            throw new ConflictException('Enrolment already exists for application.');
        }

        if ($existingSuccessful !== null && $existingSuccessful->paymentId !== $candidate->paymentId) {
            throw new ConflictException('Another successful payment already exists.');
        }

        if ($candidate->successfulMarker === 1) {
            throw new ConflictException('Payment already has successful marker.');
        }

        if (!in_array($candidate->status, [
            PaymentStatus::PENDING,
            PaymentStatus::RECONCILIATION_PENDING,
        ], true)) {
            throw new ConflictException('Payment is not eligible for success acceptance.');
        }

        if ($candidate->providerOrderId === null || $candidate->providerOrderId === '') {
            throw new DomainRuleException('Payment has no provider order binding.');
        }

        if ($provider->providerOrderId !== null
            && $provider->providerOrderId !== $candidate->providerOrderId
        ) {
            throw new DomainRuleException('Provider order reference mismatch.');
        }

        if ($provider->amountMinor !== $candidate->amountMinor) {
            throw new DomainRuleException('Provider amount does not match local snapshot.');
        }

        if (strtoupper($provider->currency) !== strtoupper($candidate->currency)) {
            throw new DomainRuleException('Provider currency does not match local snapshot.');
        }

        if (!$provider->isCapturedSuccess()) {
            throw new DomainRuleException('Provider payment is not a captured success.');
        }

        foreach ($applicationPayments as $payment) {
            if ($payment->paymentId === $candidate->paymentId) {
                continue;
            }
            if ($payment->successfulMarker === 1 || $payment->status === PaymentStatus::SUCCESSFUL) {
                throw new ConflictException('Another successful payment already exists.');
            }
        }
    }

    /**
     * @param list<Payment> $applicationPayments
     */
    public function shouldEnterReconciliationForDuplicate(
        Payment $candidate,
        array $applicationPayments,
    ): bool {
        foreach ($applicationPayments as $payment) {
            if ($payment->paymentId === $candidate->paymentId) {
                continue;
            }
            if ($payment->successfulMarker === 1 || $payment->status === PaymentStatus::SUCCESSFUL) {
                return true;
            }
        }

        return false;
    }
}
