<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use DateTimeImmutable;

/**
 * Deliberately has no general updateStatus(). Status changes go through
 * applyTransition() after PaymentStateMachine.
 */
interface PaymentRepository
{
    public function findById(int $paymentId): ?Payment;

    public function findByIdForUpdate(int $paymentId): ?Payment;

    public function findByPublicReference(string $publicReference): ?Payment;

    public function findByProviderOrderId(string $provider, string $providerOrderId): ?Payment;

    /**
     * @return list<Payment>
     */
    public function listByApplicationId(int $applicationId): array;

    /**
     * @return list<Payment>
     */
    public function lockAllForApplication(int $applicationId): array;

    public function insertCreated(
        int $applicationId,
        int $userId,
        string $publicReference,
        string $provider,
        PaymentAmountSnapshot $snapshot,
        int $attemptNumber,
        string $idempotencyKey,
        DateTimeImmutable $now,
    ): Payment;

    public function bindProviderOrder(
        int $paymentId,
        string $providerOrderId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    /**
     * CAS status transition. Returns false on row_version / from_status mismatch.
     */
    public function applyTransition(
        int $paymentId,
        string $fromStatus,
        string $toStatus,
        int $expectedRowVersion,
        DateTimeImmutable $now,
        ?string $failureCode = null,
        ?string $failureCategory = null,
        ?string $providerPaymentId = null,
        ?int $successfulMarker = null,
    ): bool;

    public function bindEnrolmentId(
        int $paymentId,
        int $enrolmentId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Claim pending (stale beyond threshold) or reconciliation_pending payments.
     *
     * @return list<Payment>
     */
    public function claimForReconciliation(
        string $workerId,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $pendingStaleSeconds,
        int $limit,
    ): array;

    public function hasActiveReconcileLease(
        int $paymentId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
    ): bool;

    public function clearReconcileLease(
        int $paymentId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
    ): bool;

    public function findSuccessfulMarkerForApplication(int $applicationId): ?Payment;

    /**
     * @return list<Payment>
     */
    public function listForFinance(
        ?string $status,
        ?string $publicReference,
        ?string $providerOrderId,
        ?int $applicationId,
        int $limit,
        int $offset,
    ): array;

    public function countForFinance(
        ?string $status,
        ?string $publicReference,
        ?string $providerOrderId,
        ?int $applicationId,
    ): int;
}
