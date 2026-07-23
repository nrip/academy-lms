<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Audit\AdmissionsAuditPayload;
use Academy\Domain\Audit\EnrolmentAuditPayload;
use Academy\Domain\Audit\PaymentAuditPayload;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\BatchCapacityPolicy;
use Academy\Domain\Learning\EnrolmentFactory;
use Academy\Domain\Learning\EnrolmentOutboxEventTypes;
use Academy\Domain\Learning\EnrolmentPublicReferenceGenerator;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\EnrolmentStatusHistoryRepository;
use Academy\Domain\Learning\EnrolmentStatusHistoryWrite;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Payments\GatewayPaymentResult;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentOutboxEventTypes;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\PaymentStatusHistoryWrite;
use Academy\Domain\Payments\SuccessfulPaymentAcceptancePolicy;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Atomic Payment success → Application admitted → Enrolment create (or duplicate/capacity paths).
 * Must be called inside an open DB transaction with no gateway I/O.
 */
final class SuccessfulPaymentAcceptanceService
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly PaymentRepository $payments,
        private readonly BatchRepository $batches,
        private readonly CourseVersionRepository $courseVersions,
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolmentFactory $enrolmentFactory,
        private readonly EnrolmentPublicReferenceGenerator $enrolmentReferences,
        private readonly EnrolmentStatusHistoryRepository $enrolmentHistory,
        private readonly ApplicationReviewAssignmentRepository $assignments,
        private readonly PaymentStateMachine $paymentStateMachine,
        private readonly ApplicationStateMachine $applicationStateMachine,
        private readonly SuccessfulPaymentAcceptancePolicy $acceptancePolicy,
        private readonly BatchCapacityPolicy $capacityPolicy,
        private readonly PaymentStatusHistoryRepository $paymentHistory,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
    ) {
    }

    public function accept(
        int $paymentId,
        GatewayPaymentResult $provider,
        string $source,
        ?string $providerEventReference = null,
    ): SuccessfulPaymentAcceptanceResult {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Lock order: Application → all Payments → Batch → Enrolment.
        // Resolving the Application without holding a candidate Payment row lock
        // prevents deadlocks when two Payments race on the same Application.
        $unlocked = $this->payments->findById($paymentId);
        if ($unlocked === null) {
            throw new NotFoundException('Payment not found.');
        }

        $application = $this->applications->findByIdForUpdate($unlocked->applicationId);
        if ($application === null) {
            throw new NotFoundException('Application not found.');
        }

        $allPayments = $this->payments->lockAllForApplication($application->applicationId);
        $candidate = null;
        foreach ($allPayments as $payment) {
            if ($payment->paymentId === $paymentId) {
                $candidate = $payment;
                break;
            }
        }
        if ($candidate === null) {
            throw new NotFoundException('Payment not found.');
        }

        $existingEnrolment = $this->enrolments->findByApplicationIdForUpdate($application->applicationId);

        if ($existingEnrolment !== null && $existingEnrolment->paymentId === $candidate->paymentId) {
            return new SuccessfulPaymentAcceptanceResult(
                SuccessfulPaymentAcceptanceResult::IDEMPOTENT,
                $candidate,
                $existingEnrolment,
            );
        }

        if ($candidate->status === PaymentStatus::SUCCESSFUL && $candidate->successfulMarker === 1) {
            $enrolment = $this->enrolments->findByPaymentId($candidate->paymentId);

            return new SuccessfulPaymentAcceptanceResult(
                SuccessfulPaymentAcceptanceResult::IDEMPOTENT,
                $candidate,
                $enrolment,
            );
        }

        if ($this->acceptancePolicy->shouldEnterReconciliationForDuplicate($candidate, $allPayments)) {
            return $this->markReconciliationPending(
                $candidate,
                $application->applicationId,
                $now,
                $source,
                $providerEventReference,
                'duplicate_capture',
                SuccessfulPaymentAcceptanceResult::DUPLICATE,
                PaymentOutboxEventTypes::RECONCILIATION_REQUIRED,
            );
        }

        $batch = $this->batches->findByIdForUpdate($candidate->batchId);
        if ($batch === null) {
            throw new NotFoundException('Batch not found.');
        }

        $occupied = $this->enrolments->countOccupiedSeatsForBatch($batch->batchId);
        if (!$this->capacityPolicy->hasAvailableSeat($batch, $occupied)) {
            return $this->markReconciliationPending(
                $candidate,
                $application->applicationId,
                $now,
                $source,
                $providerEventReference,
                'capacity_exhausted_after_payment',
                SuccessfulPaymentAcceptanceResult::CAPACITY_EXHAUSTED,
                PaymentOutboxEventTypes::CAPACITY_EXHAUSTED_AFTER_PAYMENT,
            );
        }

        try {
            $this->acceptancePolicy->assertCanAcceptSuccess(
                $application,
                $candidate,
                $provider,
                $allPayments,
                $existingEnrolment,
                $this->payments->findSuccessfulMarkerForApplication($application->applicationId),
            );
        } catch (ConflictException | DomainRuleException $exception) {
            if ($this->acceptancePolicy->shouldEnterReconciliationForDuplicate($candidate, $allPayments)) {
                return $this->markReconciliationPending(
                    $candidate,
                    $application->applicationId,
                    $now,
                    $source,
                    $providerEventReference,
                    'duplicate_capture',
                    SuccessfulPaymentAcceptanceResult::DUPLICATE,
                    PaymentOutboxEventTypes::RECONCILIATION_REQUIRED,
                );
            }

            return new SuccessfulPaymentAcceptanceResult(
                SuccessfulPaymentAcceptanceResult::REJECTED,
                $candidate,
                null,
                $exception->getMessage(),
            );
        }

        $fromPaymentStatus = $candidate->status;
        $this->paymentStateMachine->assertCanTransition(
            $fromPaymentStatus,
            PaymentStatus::SUCCESSFUL,
            ['system'],
        );

        try {
            $applied = $this->payments->applyTransition(
                $candidate->paymentId,
                $fromPaymentStatus,
                PaymentStatus::SUCCESSFUL,
                $candidate->rowVersion,
                $now,
                null,
                null,
                $provider->providerPaymentId,
                1,
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $reloaded = $this->payments->findByIdForUpdate($candidate->paymentId);
                if ($reloaded === null) {
                    throw $exception;
                }

                return $this->markReconciliationPending(
                    $reloaded,
                    $application->applicationId,
                    $now,
                    $source,
                    $providerEventReference,
                    'successful_marker_conflict',
                    SuccessfulPaymentAcceptanceResult::DUPLICATE,
                    PaymentOutboxEventTypes::RECONCILIATION_REQUIRED,
                );
            }
            throw $exception;
        }

        if (!$applied) {
            throw new ConflictException('Payment success transition lost a version race.');
        }

        $successful = $this->payments->findById($candidate->paymentId);
        if ($successful === null) {
            throw new NotFoundException('Payment not found after success transition.');
        }

        $this->paymentHistory->append(new PaymentStatusHistoryWrite(
            paymentId: $successful->paymentId,
            applicationId: $application->applicationId,
            statusBefore: $fromPaymentStatus,
            statusAfter: PaymentStatus::SUCCESSFUL,
            source: $source,
            providerEventReference: $providerEventReference,
            reason: 'capture_accepted',
            failureCategory: null,
            actorUserId: null,
            createdAt: $now,
        ));

        $this->applicationStateMachine->assertCanTransition(
            ApplicationStatus::PAYMENT_PENDING,
            ApplicationStatus::ADMITTED,
            ['system'],
        );
        $admitted = $this->applications->applyTransition(
            $application->applicationId,
            ApplicationStatus::PAYMENT_PENDING,
            ApplicationStatus::ADMITTED,
            null,
            $application->stateVersion,
            $now,
        );
        if (!$admitted) {
            throw new ConflictException('Application admit transition lost a version race.');
        }

        $version = $this->courseVersions->findById($candidate->courseVersionId);
        if ($version === null) {
            throw new NotFoundException('Course version not found.');
        }

        $lifecycle = $this->enrolmentFactory->initialLifecycle($batch->startsAt, $now);
        $academic = $this->enrolmentFactory->initialAcademicStatus($lifecycle);
        $activatedAt = $this->enrolmentFactory->activatedAt($lifecycle, $now);
        $publicRef = $this->enrolmentReferences->generate($application->applicationId, $now);

        $enrolment = $this->enrolments->insertCreated(
            $publicRef,
            $application->applicationId,
            $candidate->userId,
            $version->courseId,
            $candidate->courseVersionId,
            $candidate->batchId,
            $successful->paymentId,
            $lifecycle,
            $academic,
            $now,
            $activatedAt,
            $batch->accessExpiresAt,
            $now,
        );

        if (!$this->payments->bindEnrolmentId(
            $successful->paymentId,
            $enrolment->enrolmentId,
            $successful->rowVersion,
            $now,
        )) {
            throw new ConflictException('Failed to bind enrolment to payment.');
        }

        $this->enrolmentHistory->append(new EnrolmentStatusHistoryWrite(
            enrolmentId: $enrolment->enrolmentId,
            applicationId: $application->applicationId,
            lifecycleBefore: $lifecycle,
            lifecycleAfter: $lifecycle,
            source: $source,
            reason: 'created_on_admission',
            actorUserId: null,
            createdAt: $now,
        ));

        $assignment = $this->assignments->lockActiveForApplication($application->applicationId);
        if ($assignment !== null) {
            $this->assignments->complete($assignment->assignmentId, $assignment->rowVersion, $now);
        }

        $this->outbox->enqueue(
            PaymentOutboxEventTypes::SUCCESSFUL,
            'payment',
            (string) $successful->paymentId,
            [
                'payment_id' => $successful->paymentId,
                'application_id' => $application->applicationId,
                'enrolment_id' => $enrolment->enrolmentId,
                'amount_minor' => $successful->amountMinor,
                'currency' => $successful->currency,
            ],
            'payment.successful:' . $successful->paymentId,
        );
        $this->outbox->enqueue(
            PaymentOutboxEventTypes::APPLICATION_ADMITTED,
            'application',
            (string) $application->applicationId,
            [
                'application_id' => $application->applicationId,
                'payment_id' => $successful->paymentId,
                'enrolment_id' => $enrolment->enrolmentId,
            ],
            'application.admitted:' . $application->applicationId,
        );
        $this->outbox->enqueue(
            EnrolmentOutboxEventTypes::CREATED,
            'enrolment',
            (string) $enrolment->enrolmentId,
            [
                'enrolment_id' => $enrolment->enrolmentId,
                'application_id' => $application->applicationId,
                'payment_id' => $successful->paymentId,
                'batch_id' => $enrolment->batchId,
                'lifecycle_status' => $enrolment->lifecycleStatus,
            ],
            'enrolment.created:' . $enrolment->enrolmentId,
        );

        $this->audit->record(
            new PaymentAuditPayload(
                action: 'payment.success_accepted',
                entityType: 'payment',
                entityId: (string) $successful->paymentId,
                previous: ['status' => $fromPaymentStatus],
                next: [
                    'application_id' => $application->applicationId,
                    'payment_id' => $successful->paymentId,
                    'enrolment_id' => $enrolment->enrolmentId,
                    'provider' => $successful->provider,
                    'provider_order_id' => $successful->providerOrderId,
                    'provider_payment_id' => $provider->providerPaymentId,
                    'amount_minor' => $successful->amountMinor,
                    'currency' => $successful->currency,
                    'status' => PaymentStatus::SUCCESSFUL,
                    'successful_marker' => 1,
                    'result' => 'accepted',
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: $source,
        );
        $this->audit->record(
            new AdmissionsAuditPayload(
                action: 'application.admitted',
                entityType: 'application',
                entityId: (string) $application->applicationId,
                previous: ['status' => ApplicationStatus::PAYMENT_PENDING],
                next: [
                    'application_id' => $application->applicationId,
                    'status' => ApplicationStatus::ADMITTED,
                    'payment_id' => $successful->paymentId,
                    'enrolment_id' => $enrolment->enrolmentId,
                    'result' => 'ok',
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: $source,
        );
        $this->audit->record(
            new EnrolmentAuditPayload(
                action: 'enrolment.created',
                entityType: 'enrolment',
                entityId: (string) $enrolment->enrolmentId,
                next: [
                    'enrolment_id' => $enrolment->enrolmentId,
                    'application_id' => $application->applicationId,
                    'user_id' => $enrolment->userId,
                    'payment_id' => $successful->paymentId,
                    'batch_id' => $enrolment->batchId,
                    'course_id' => $enrolment->courseId,
                    'course_version_id' => $enrolment->courseVersionId,
                    'lifecycle_status' => $enrolment->lifecycleStatus,
                    'academic_status' => $enrolment->academicStatus,
                    'public_reference' => $enrolment->publicReference,
                    'result' => 'ok',
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: $source,
        );

        $bound = $this->payments->findById($successful->paymentId) ?? $successful;

        return new SuccessfulPaymentAcceptanceResult(
            SuccessfulPaymentAcceptanceResult::ACCEPTED,
            $bound,
            $enrolment,
        );
    }

    private function markReconciliationPending(
        Payment $candidate,
        int $applicationId,
        DateTimeImmutable $now,
        string $source,
        ?string $providerEventReference,
        string $reason,
        string $outcome,
        string $outboxType,
    ): SuccessfulPaymentAcceptanceResult {
        if ($candidate->status === PaymentStatus::RECONCILIATION_PENDING) {
            return new SuccessfulPaymentAcceptanceResult($outcome, $candidate, null, $reason);
        }

        if (!in_array($candidate->status, [PaymentStatus::PENDING, PaymentStatus::SUCCESSFUL], true)) {
            // Only pending (or successful→recon for matrix completeness) may move here in WP-06 paths.
            if ($candidate->status !== PaymentStatus::PENDING) {
                return new SuccessfulPaymentAcceptanceResult(
                    SuccessfulPaymentAcceptanceResult::REJECTED,
                    $candidate,
                    null,
                    'payment_not_eligible_for_reconciliation',
                );
            }
        }

        $this->paymentStateMachine->assertCanTransition(
            $candidate->status,
            PaymentStatus::RECONCILIATION_PENDING,
            ['system'],
        );

        $applied = $this->payments->applyTransition(
            $candidate->paymentId,
            $candidate->status,
            PaymentStatus::RECONCILIATION_PENDING,
            $candidate->rowVersion,
            $now,
            null,
            $reason,
            null,
            null,
        );
        if (!$applied) {
            $reloaded = $this->payments->findById($candidate->paymentId) ?? $candidate;

            return new SuccessfulPaymentAcceptanceResult($outcome, $reloaded, null, $reason);
        }

        $updated = $this->payments->findById($candidate->paymentId) ?? $candidate;
        $this->paymentHistory->append(new PaymentStatusHistoryWrite(
            paymentId: $updated->paymentId,
            applicationId: $applicationId,
            statusBefore: $candidate->status,
            statusAfter: PaymentStatus::RECONCILIATION_PENDING,
            source: $source,
            providerEventReference: $providerEventReference,
            reason: $reason,
            failureCategory: $reason,
            actorUserId: null,
            createdAt: $now,
        ));

        $this->outbox->enqueue(
            $outboxType,
            'payment',
            (string) $updated->paymentId,
            [
                'payment_id' => $updated->paymentId,
                'application_id' => $applicationId,
                'reason' => $reason,
            ],
            $outboxType . ':' . $updated->paymentId . ':' . $reason,
        );

        $auditAction = $reason === 'capacity_exhausted_after_payment'
            ? 'capacity.exhausted_after_payment'
            : 'payment.duplicate_capture_detected';

        $this->audit->record(
            new PaymentAuditPayload(
                action: $auditAction,
                entityType: 'payment',
                entityId: (string) $updated->paymentId,
                previous: ['status' => $candidate->status],
                next: [
                    'application_id' => $applicationId,
                    'payment_id' => $updated->paymentId,
                    'batch_id' => $updated->batchId,
                    'status' => PaymentStatus::RECONCILIATION_PENDING,
                    'failure_category' => $reason,
                    'result' => $outcome,
                ],
            ),
            actorType: 'system',
            actorUserId: null,
            source: $source,
        );

        return new SuccessfulPaymentAcceptanceResult($outcome, $updated, null, $reason);
    }
}
