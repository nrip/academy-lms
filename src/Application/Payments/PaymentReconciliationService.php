<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\PaymentAuditPayload;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\PaymentStatusHistoryWrite;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaymentReconciliationService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly PaymentRepository $payments,
        private readonly PaymentGateway $gateway,
        private readonly SuccessfulPaymentAcceptanceService $acceptance,
        private readonly PaymentStateMachine $paymentStateMachine,
        private readonly PaymentStatusHistoryRepository $paymentHistory,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
        private readonly int $leaseSeconds,
        private readonly int $pendingStaleSeconds,
    ) {
    }

    public function run(string $workerId, int $limit = 10): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $claimed = $this->transactions->run(
            fn (): array => $this->payments->claimForReconciliation(
                $workerId,
                $now,
                $this->leaseSeconds,
                $this->pendingStaleSeconds,
                $limit,
            ),
        );

        $processed = 0;
        foreach ($claimed as $payment) {
            try {
                if ($this->reconcileClaimed($payment, $workerId, 'reconciliation')) {
                    ++$processed;
                }
            } catch (Throwable $exception) {
                $this->logger->error('payment.reconciliation.failed', [
                    'payment_id' => $payment->paymentId,
                    'error' => $exception->getMessage(),
                ]);
                $this->transactions->run(function () use ($payment, $workerId): void {
                    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    if ($payment->reconcileLeaseToken !== null) {
                        $this->payments->clearReconcileLease(
                            $payment->paymentId,
                            $workerId,
                            $payment->reconcileLeaseToken,
                            $now,
                        );
                    }
                });
            }
        }

        return $processed;
    }

    public function retryByFinance(AuthContext $auth, int $paymentId, string $reason): Payment
    {
        $this->authorization->require($auth, 'finance.payment.retry_reconciliation');
        if (trim($reason) === '') {
            throw new ValidationException('Reason is required.', ['reason' => ['Provide a reconciliation reason.']]);
        }

        $workerId = 'finance:' . (string) $auth->userId;

        $claimed = $this->transactions->run(function () use ($paymentId, $workerId): Payment {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $payment = $this->payments->findByIdForUpdate($paymentId);
            if ($payment === null) {
                throw new NotFoundException('Payment not found.');
            }
            if (!in_array($payment->status, [
                PaymentStatus::PENDING,
                PaymentStatus::RECONCILIATION_PENDING,
            ], true)) {
                throw new ConflictException('Payment is not eligible for reconciliation retry.');
            }

            $batch = $this->payments->claimForReconciliation(
                $workerId,
                $now,
                $this->leaseSeconds,
                0,
                100,
            );
            foreach ($batch as $item) {
                if ($item->paymentId === $paymentId) {
                    return $item;
                }
            }

            throw new ConflictException('Unable to claim payment for reconciliation.');
        });

        $this->audit->record(
            new PaymentAuditPayload(
                action: 'payment.reconciliation_started',
                entityType: 'payment',
                entityId: (string) $paymentId,
                next: [
                    'payment_id' => $paymentId,
                    'application_id' => $claimed->applicationId,
                    'status' => $claimed->status,
                    'result' => 'finance_retry',
                ],
                reason: $reason,
            ),
            actorType: 'user',
            actorUserId: $auth->userId,
            source: 'finance',
        );

        $this->reconcileClaimed($claimed, $workerId, 'finance_reconciliation');

        $updated = $this->payments->findById($paymentId);
        if ($updated === null) {
            throw new NotFoundException('Payment not found after reconciliation.');
        }

        return $updated;
    }

    private function reconcileClaimed(Payment $payment, string $workerId, string $source): bool
    {
        if ($payment->providerOrderId === null || $payment->reconcileLeaseToken === null) {
            return false;
        }

        // Gateway I/O outside DB transaction.
        $providerPayments = $this->gateway->fetchPaymentsForOrder($payment->providerOrderId);
        $captured = null;
        $failed = null;
        foreach ($providerPayments as $providerPayment) {
            if ($providerPayment->isCapturedSuccess()) {
                $captured = $providerPayment;
                break;
            }
            if ($providerPayment->isFailed()) {
                $failed = $providerPayment;
            }
        }

        return $this->transactions->runWithDeadlockRetry(function () use ($payment, $workerId, $source, $captured, $failed): bool {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (!$this->payments->hasActiveReconcileLease(
                $payment->paymentId,
                $workerId,
                (string) $payment->reconcileLeaseToken,
                $now,
            )) {
                return false;
            }

            $locked = $this->payments->findByIdForUpdate($payment->paymentId);
            if ($locked === null || $locked->rowVersion !== $payment->rowVersion) {
                $this->payments->clearReconcileLease(
                    $payment->paymentId,
                    $workerId,
                    (string) $payment->reconcileLeaseToken,
                    $now,
                );

                return false;
            }

            if ($captured !== null) {
                $this->acceptance->accept($locked->paymentId, $captured, $source, $captured->providerPaymentId);
                $this->payments->clearReconcileLease(
                    $locked->paymentId,
                    $workerId,
                    (string) $payment->reconcileLeaseToken,
                    $now,
                );
                $this->audit->record(
                    new PaymentAuditPayload(
                        action: 'payment.reconciliation_completed',
                        entityType: 'payment',
                        entityId: (string) $locked->paymentId,
                        next: [
                            'payment_id' => $locked->paymentId,
                            'application_id' => $locked->applicationId,
                            'result' => 'ok',
                        ],
                    ),
                    actorType: 'system',
                    actorUserId: null,
                    source: $source,
                );

                return true;
            }

            if ($failed !== null && $locked->status === PaymentStatus::PENDING) {
                $this->paymentStateMachine->assertCanTransition(
                    PaymentStatus::PENDING,
                    PaymentStatus::FAILED,
                    ['system'],
                    $failed->failureCode ?? 'payment_failed',
                );
                $applied = $this->payments->applyTransition(
                    $locked->paymentId,
                    PaymentStatus::PENDING,
                    PaymentStatus::FAILED,
                    $locked->rowVersion,
                    $now,
                    $failed->failureCode,
                    $failed->failureCategory ?? 'gateway_declined',
                    $failed->providerPaymentId,
                    null,
                );
                if ($applied) {
                    $this->paymentHistory->append(new PaymentStatusHistoryWrite(
                        paymentId: $locked->paymentId,
                        applicationId: $locked->applicationId,
                        statusBefore: PaymentStatus::PENDING,
                        statusAfter: PaymentStatus::FAILED,
                        source: $source,
                        providerEventReference: $failed->providerPaymentId,
                        reason: $failed->failureCode ?? 'payment_failed',
                        failureCategory: $failed->failureCategory ?? 'gateway_declined',
                        actorUserId: null,
                        createdAt: $now,
                    ));
                }
            }

            $this->payments->clearReconcileLease(
                $locked->paymentId,
                $workerId,
                (string) $payment->reconcileLeaseToken,
                $now,
            );

            return true;
        });
    }
}
