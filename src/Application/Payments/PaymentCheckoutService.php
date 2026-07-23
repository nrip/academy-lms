<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Audit\PaymentAuditPayload;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentInitiationPolicy;
use Academy\Domain\Payments\PaymentOutboxEventTypes;
use Academy\Domain\Payments\PaymentPublicReferenceGenerator;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\PaymentStatusHistoryWrite;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class PaymentCheckoutService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly BatchRepository $batches,
        private readonly CourseVersionRepository $courseVersions,
        private readonly PaymentRepository $payments,
        private readonly PaymentStatusHistoryRepository $paymentHistory,
        private readonly PaymentInitiationPolicy $initiationPolicy,
        private readonly PaymentStateMachine $paymentStateMachine,
        private readonly PaymentPublicReferenceGenerator $publicReferences,
        private readonly PaymentGateway $gateway,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function getCheckoutPage(AuthContext $auth, int $applicationId): PaymentCheckoutView
    {
        $this->authorization->require($auth, 'payment.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }

        $attempts = $this->payments->listByApplicationId($applicationId);
        $snapshot = $this->buildSnapshotPreview($application);

        $canInitiate = false;
        try {
            $this->initiationPolicy->assertCanInitiate($application, $userId, $attempts);
            $canInitiate = $snapshot !== null;
        } catch (ConflictException | DomainRuleException | NotFoundException) {
            $canInitiate = false;
        }

        $gatewayKey = null;
        try {
            $gatewayKey = $this->gateway->publicKeyId();
        } catch (ExternalServiceException) {
            $gatewayKey = null;
        }

        return new PaymentCheckoutView(
            application: $application,
            snapshotPreview: $snapshot,
            attempts: $attempts,
            canInitiate: $canInitiate,
            gatewayPublicKeyId: $gatewayKey,
        );
    }

    public function initiate(AuthContext $auth, int $applicationId): Payment
    {
        $this->authorization->require($auth, 'payment.initiate_own');
        $userId = $this->requireUserId($auth);

        $this->rateLimiter->hit('payments.checkout', [
            ['type' => 'application', 'value' => (string) $applicationId],
            ['type' => 'user', 'value' => (string) $userId],
        ]);

        $created = $this->transactions->run(function () use ($auth, $userId, $applicationId): Payment {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->applications->findByIdForUpdate($applicationId);
            if ($application === null || $application->userId !== $userId) {
                throw new NotFoundException('Application not found.');
            }

            $existing = $this->payments->lockAllForApplication($applicationId);
            $resumable = $this->findUnboundCreatedAttempt($existing, $userId);
            if ($resumable !== null) {
                // Resume gateway bind after a prior process created the row but did not finish.
                return $resumable;
            }

            if ($existing !== []) {
                $this->authorization->require($auth, 'payment.retry_own');
            }
            $this->initiationPolicy->assertCanInitiate($application, $userId, $existing);

            $batch = $this->batches->findById($application->batchId);
            $version = $this->courseVersions->findById($application->courseVersionId);
            if ($batch === null || $version === null) {
                throw new DomainRuleException('Commercial configuration for this application is missing.');
            }

            $snapshot = PaymentAmountSnapshot::fromBatchAndVersion($batch, $version);
            $attemptNumber = $this->initiationPolicy->nextAttemptNumber($existing);
            $publicReference = $this->publicReferences->generate($applicationId, $attemptNumber);
            $idempotencyKey = sprintf('pay:app:%d:attempt:%d', $applicationId, $attemptNumber);

            $payment = $this->payments->insertCreated(
                $applicationId,
                $userId,
                $publicReference,
                $this->gateway->provider(),
                $snapshot,
                $attemptNumber,
                $idempotencyKey,
                $now,
            );

            $this->paymentHistory->append(new PaymentStatusHistoryWrite(
                paymentId: $payment->paymentId,
                applicationId: $applicationId,
                statusBefore: '',
                statusAfter: PaymentStatus::CREATED,
                source: 'learner_initiate',
                providerEventReference: null,
                reason: 'payment_attempt_created',
                failureCategory: null,
                actorUserId: $userId,
                createdAt: $now,
            ));

            $this->outbox->enqueue(
                PaymentOutboxEventTypes::ATTEMPT_CREATED,
                'payment',
                (string) $payment->paymentId,
                [
                    'payment_id' => $payment->paymentId,
                    'application_id' => $applicationId,
                    'public_reference' => $payment->publicReference,
                    'amount_minor' => $payment->amountMinor,
                    'currency' => $payment->currency,
                    'attempt_number' => $payment->attemptNumber,
                    'status' => PaymentStatus::CREATED,
                ],
                PaymentOutboxEventTypes::ATTEMPT_CREATED . ':' . $payment->paymentId,
            );

            $this->audit->record(
                new PaymentAuditPayload(
                    action: 'payment.attempt_created',
                    entityType: 'payment',
                    entityId: (string) $payment->paymentId,
                    previous: [],
                    next: [
                        'user_id' => $userId,
                        'application_id' => $applicationId,
                        'payment_id' => $payment->paymentId,
                        'public_reference' => $payment->publicReference,
                        'provider' => $payment->provider,
                        'amount_minor' => $payment->amountMinor,
                        'currency' => $payment->currency,
                        'status' => PaymentStatus::CREATED,
                        'attempt_number' => $payment->attemptNumber,
                        'row_version' => $payment->rowVersion,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'payments',
            );

            return $payment;
        });

        if ($created->status === PaymentStatus::PENDING && $created->providerOrderId !== null) {
            return $created;
        }

        return $this->bindGatewayOrderWithRetry($created, $userId, $applicationId);
    }

    /**
     * @param list<Payment> $existing
     */
    private function findUnboundCreatedAttempt(array $existing, int $userId): ?Payment
    {
        foreach ($existing as $attempt) {
            if ($attempt->status === PaymentStatus::CREATED
                && $attempt->providerOrderId === null
                && $attempt->belongsToUser($userId)
            ) {
                return $attempt;
            }
        }

        return null;
    }

    private function bindGatewayOrderWithRetry(Payment $created, int $userId, int $applicationId): Payment
    {
        $attempts = 0;
        $maxAttempts = 5;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            ++$attempts;
            try {
                return $this->createAndBindGatewayOrder($created, $userId, $applicationId);
            } catch (\PDOException $exception) {
                $lastException = $exception;
                $sqlState = $exception->errorInfo[0] ?? '';
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                $isDeadlock = $sqlState === '40001' || $driverCode === 1213 || $driverCode === 1205;
                if (!$isDeadlock || $attempts >= $maxAttempts) {
                    throw $exception;
                }
                usleep(random_int(5_000, 25_000));

                $reloaded = $this->payments->findById($created->paymentId);
                if ($reloaded !== null && $reloaded->status === PaymentStatus::PENDING && $reloaded->providerOrderId !== null) {
                    return $reloaded;
                }
                if ($reloaded !== null) {
                    $created = $reloaded;
                }
            }
        }

        throw $lastException;
    }

    private function createAndBindGatewayOrder(Payment $created, int $userId, int $applicationId): Payment
    {
        $reloaded = $this->payments->findById($created->paymentId);
        if ($reloaded !== null && $reloaded->status === PaymentStatus::PENDING && $reloaded->providerOrderId !== null) {
            return $reloaded;
        }
        if ($reloaded !== null) {
            $created = $reloaded;
        }

        try {
            $order = $this->gateway->createOrder(
                $created->amountMinor,
                $created->currency,
                $created->publicReference,
                [
                    'application_id' => $applicationId,
                    'payment_id' => $created->paymentId,
                    'attempt_number' => $created->attemptNumber,
                ],
                $created->idempotencyKey,
            );
        } catch (ExternalServiceException $exception) {
            $this->markGatewayCreateFailed($created, $userId, $exception->getMessage());
            throw $exception;
        }

        $bound = $this->transactions->run(function () use ($created, $order, $userId): array {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $payment = $this->payments->findByIdForUpdate($created->paymentId);
            if ($payment === null) {
                throw new NotFoundException('Payment not found.');
            }
            if ($payment->status === PaymentStatus::PENDING && $payment->providerOrderId !== null) {
                return ['ok' => true, 'payment' => $payment];
            }
            if ($payment->status !== PaymentStatus::CREATED) {
                throw new ConflictException('Payment was updated concurrently during gateway bind.');
            }

            if ($order->amountMinor !== $payment->amountMinor
                || strtoupper($order->currency) !== strtoupper($payment->currency)
            ) {
                // Persist failure inside this txn, then signal the caller to throw after commit.
                $this->failCreatedPayment(
                    $payment,
                    $userId,
                    $now,
                    'gateway_amount_mismatch',
                    'gateway_error',
                    'Gateway order amount or currency did not match the payment snapshot.',
                );

                return ['ok' => false, 'payment' => null];
            }

            $rowVersion = $payment->rowVersion;
            if ($payment->providerOrderId === null) {
                if (!$this->payments->bindProviderOrder(
                    $payment->paymentId,
                    $order->providerOrderId,
                    $rowVersion,
                    $now,
                )) {
                    throw new ConflictException('Payment was updated concurrently during gateway bind.');
                }
                $rowVersion++;
            }

            $this->paymentStateMachine->transition(
                PaymentStatus::CREATED,
                PaymentStatus::PENDING,
                ['system'],
                $now,
                'gateway_order_bound',
            );

            if (!$this->payments->applyTransition(
                $payment->paymentId,
                PaymentStatus::CREATED,
                PaymentStatus::PENDING,
                $rowVersion,
                $now,
            )) {
                throw new ConflictException('Payment was updated concurrently during gateway bind.');
            }

            $this->paymentHistory->append(new PaymentStatusHistoryWrite(
                paymentId: $payment->paymentId,
                applicationId: $payment->applicationId,
                statusBefore: PaymentStatus::CREATED,
                statusAfter: PaymentStatus::PENDING,
                source: 'gateway_bind',
                providerEventReference: $order->providerOrderId,
                reason: 'gateway_order_bound',
                failureCategory: null,
                actorUserId: null,
                createdAt: $now,
            ));

            $this->outbox->enqueue(
                PaymentOutboxEventTypes::GATEWAY_ORDER_BOUND,
                'payment',
                (string) $payment->paymentId,
                [
                    'payment_id' => $payment->paymentId,
                    'application_id' => $payment->applicationId,
                    'provider_order_id' => $order->providerOrderId,
                    'status' => PaymentStatus::PENDING,
                ],
                PaymentOutboxEventTypes::GATEWAY_ORDER_BOUND . ':' . $payment->paymentId,
            );

            $this->audit->record(
                new PaymentAuditPayload(
                    action: 'payment.gateway_order_bound',
                    entityType: 'payment',
                    entityId: (string) $payment->paymentId,
                    previous: [
                        'status' => PaymentStatus::CREATED,
                        'row_version' => $payment->rowVersion,
                    ],
                    next: [
                        'user_id' => $userId,
                        'application_id' => $payment->applicationId,
                        'payment_id' => $payment->paymentId,
                        'public_reference' => $payment->publicReference,
                        'provider' => $payment->provider,
                        'provider_order_id' => $order->providerOrderId,
                        'amount_minor' => $payment->amountMinor,
                        'currency' => $payment->currency,
                        'status' => PaymentStatus::PENDING,
                        'attempt_number' => $payment->attemptNumber,
                        'row_version' => $rowVersion + 1,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'system',
                actorUserId: null,
                source: 'payments',
            );

            $pending = $this->payments->findById($payment->paymentId);
            if ($pending === null) {
                throw new NotFoundException('Payment not found.');
            }

            return ['ok' => true, 'payment' => $pending];
        });

        if ($bound['ok'] !== true || !$bound['payment'] instanceof Payment) {
            throw new ExternalServiceException('Gateway order amount or currency mismatch.');
        }

        return $bound['payment'];
    }

    public function getPayment(AuthContext $auth, int $applicationId, int $paymentId): Payment
    {
        $this->authorization->require($auth, 'payment.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }

        $payment = $this->payments->findById($paymentId);
        if ($payment === null
            || !$payment->belongsToApplication($applicationId)
            || !$payment->belongsToUser($userId)
        ) {
            throw new NotFoundException('Payment not found.');
        }

        return $payment;
    }

    public function recordCheckoutReturn(AuthContext $auth, int $applicationId, int $paymentId): Payment
    {
        $this->authorization->require($auth, 'payment.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }

        $payment = $this->payments->findById($paymentId);
        if ($payment === null
            || !$payment->belongsToApplication($applicationId)
            || !$payment->belongsToUser($userId)
        ) {
            throw new NotFoundException('Payment not found.');
        }

        if (!in_array($payment->status, [PaymentStatus::CREATED, PaymentStatus::PENDING], true)) {
            throw new ConflictException('Checkout return is only valid for an in-progress payment attempt.');
        }

        // Informational only — never transition Payment to successful or mutate Application.
        $this->audit->record(
            new PaymentAuditPayload(
                action: 'payment.checkout_return_recorded',
                entityType: 'payment',
                entityId: (string) $payment->paymentId,
                previous: [
                    'status' => $payment->status,
                ],
                next: [
                    'user_id' => $userId,
                    'application_id' => $applicationId,
                    'payment_id' => $payment->paymentId,
                    'public_reference' => $payment->publicReference,
                    'provider' => $payment->provider,
                    'provider_order_id' => $payment->providerOrderId,
                    'amount_minor' => $payment->amountMinor,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'attempt_number' => $payment->attemptNumber,
                    'result' => 'informational',
                ],
            ),
            actorType: 'user',
            actorUserId: $userId,
            source: 'payments',
        );

        return $payment;
    }

    public function getPaymentResult(AuthContext $auth, int $applicationId): PaymentResultView
    {
        $this->authorization->require($auth, 'payment.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }

        $attempts = $this->payments->listByApplicationId($applicationId);
        $primary = $attempts === [] ? null : $attempts[array_key_last($attempts)];
        $isConfirming = $primary !== null && PaymentStatus::isInFlight($primary->status);

        $headline = match (true) {
            $primary === null => 'No payment attempt yet',
            $isConfirming => 'Confirming payment…',
            $primary->status === PaymentStatus::FAILED => 'Payment failed',
            $primary->status === PaymentStatus::CANCELLED => 'Payment cancelled',
            $primary->status === PaymentStatus::EXPIRED => 'Payment expired',
            $primary->status === PaymentStatus::SUCCESSFUL => $application->status === \Academy\Domain\Admissions\ApplicationStatus::ADMITTED
                ? 'Payment successful — admitted'
                : 'Payment successful',
            $primary->status === PaymentStatus::RECONCILIATION_PENDING => 'Payment reconciliation pending',
            default => 'Payment status: ' . $primary->status,
        };

        return new PaymentResultView(
            application: $application,
            primaryPayment: $primary,
            attempts: $attempts,
            isConfirming: $isConfirming,
            statusHeadline: $headline,
        );
    }

    private function markGatewayCreateFailed(Payment $created, int $userId, string $reason): void
    {
        $this->transactions->run(function () use ($created, $userId, $reason): void {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $payment = $this->payments->findByIdForUpdate($created->paymentId);
            if ($payment === null || $payment->status !== PaymentStatus::CREATED) {
                return;
            }

            $this->failCreatedPayment(
                $payment,
                $userId,
                $now,
                'order_create_failed',
                'gateway_error',
                $reason !== '' ? $reason : 'gateway_create_failed',
            );
        });
    }

    private function failCreatedPayment(
        Payment $payment,
        int $userId,
        DateTimeImmutable $now,
        string $failureCode,
        string $failureCategory,
        string $reason,
    ): void {
        $this->paymentStateMachine->transition(
            PaymentStatus::CREATED,
            PaymentStatus::FAILED,
            ['system'],
            $now,
            $reason,
        );

        if (!$this->payments->applyTransition(
            $payment->paymentId,
            PaymentStatus::CREATED,
            PaymentStatus::FAILED,
            $payment->rowVersion,
            $now,
            $failureCode,
            $failureCategory,
        )) {
            throw new ConflictException('Payment was updated concurrently while recording gateway failure.');
        }

        $this->paymentHistory->append(new PaymentStatusHistoryWrite(
            paymentId: $payment->paymentId,
            applicationId: $payment->applicationId,
            statusBefore: PaymentStatus::CREATED,
            statusAfter: PaymentStatus::FAILED,
            source: 'gateway_create_failed',
            providerEventReference: null,
            reason: $reason,
            failureCategory: $failureCategory,
            actorUserId: null,
            createdAt: $now,
        ));

        $this->outbox->enqueue(
            PaymentOutboxEventTypes::FAILED,
            'payment',
            (string) $payment->paymentId,
            [
                'payment_id' => $payment->paymentId,
                'application_id' => $payment->applicationId,
                'status' => PaymentStatus::FAILED,
                'failure_category' => $failureCategory,
            ],
            PaymentOutboxEventTypes::FAILED . ':' . $payment->paymentId,
        );

        $this->audit->record(
            new PaymentAuditPayload(
                action: 'payment.failed',
                entityType: 'payment',
                entityId: (string) $payment->paymentId,
                previous: [
                    'status' => PaymentStatus::CREATED,
                    'row_version' => $payment->rowVersion,
                ],
                next: [
                    'user_id' => $userId,
                    'application_id' => $payment->applicationId,
                    'payment_id' => $payment->paymentId,
                    'public_reference' => $payment->publicReference,
                    'provider' => $payment->provider,
                    'amount_minor' => $payment->amountMinor,
                    'currency' => $payment->currency,
                    'status' => PaymentStatus::FAILED,
                    'attempt_number' => $payment->attemptNumber,
                    'failure_category' => $failureCategory,
                    'result' => 'failed',
                ],
                reason: $reason,
            ),
            actorType: 'system',
            actorUserId: null,
            source: 'payments',
        );
    }

    private function buildSnapshotPreview(Application $application): ?PaymentAmountSnapshot
    {
        $batch = $this->batches->findById($application->batchId);
        $version = $this->courseVersions->findById($application->courseVersionId);
        if ($batch === null || $version === null) {
            return null;
        }

        try {
            return PaymentAmountSnapshot::fromBatchAndVersion($batch, $version);
        } catch (DomainRuleException) {
            return null;
        }
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
