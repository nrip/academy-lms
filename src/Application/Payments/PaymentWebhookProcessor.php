<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\PaymentAuditPayload;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Payments\GatewayPaymentResult;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\PaymentStatusHistoryWrite;
use Academy\Domain\Payments\Webhook\PaymentWebhookEvent;
use Academy\Domain\Payments\Webhook\PaymentWebhookEventRepository;
use Academy\Domain\Payments\Webhook\RazorpayWebhookEventTypes;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaymentWebhookProcessor
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly PaymentWebhookEventRepository $webhookEvents,
        private readonly PaymentRepository $payments,
        private readonly PaymentGateway $gateway,
        private readonly SuccessfulPaymentAcceptanceService $acceptance,
        private readonly PaymentStateMachine $paymentStateMachine,
        private readonly PaymentStatusHistoryRepository $paymentHistory,
        private readonly AuditService $audit,
        private readonly LoggerInterface $logger,
        private readonly int $leaseSeconds,
        private readonly int $maxAttempts,
        private readonly int $backoffBaseSeconds,
        private readonly int $backoffCapSeconds,
    ) {
    }

    public function run(string $workerId, int $limit = 10): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $claimed = $this->transactions->run(
            fn (): array => $this->webhookEvents->claimPending($workerId, $now, $this->leaseSeconds, $limit),
        );

        $processed = 0;
        foreach ($claimed as $event) {
            try {
                $this->processOne($event, $workerId);
                ++$processed;
            } catch (Throwable $exception) {
                $this->logger->error('payment.webhook.process_failed', [
                    'webhook_event_id' => $event->webhookEventId,
                    'error' => $exception->getMessage(),
                ]);
                $this->transactions->run(function () use ($event, $workerId, $now): void {
                    $fresh = $this->webhookEvents->findById($event->webhookEventId);
                    if ($fresh === null || $fresh->leaseToken === null) {
                        return;
                    }
                    $this->webhookEvents->markRetryOrDead(
                        $fresh->webhookEventId,
                        $workerId,
                        $fresh->leaseToken,
                        $fresh->rowVersion,
                        'processing_exception',
                        $now,
                        $this->maxAttempts,
                        $this->backoffBaseSeconds,
                        $this->backoffCapSeconds,
                    );
                });
            }
        }

        return $processed;
    }

    private function processOne(PaymentWebhookEvent $event, string $workerId): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($event->eventType === RazorpayWebhookEventTypes::PAYMENT_AUTHORIZED) {
            $this->transactions->run(function () use ($event, $workerId, $now): void {
                $this->finishIgnored($event, $workerId, 'authorized_noop', $now);
            });

            return;
        }

        if (!RazorpayWebhookEventTypes::isHandled($event->eventType)) {
            $this->transactions->run(function () use ($event, $workerId, $now): void {
                $this->finishIgnored($event, $workerId, 'unsupported_event', $now);
            });

            return;
        }

        $payment = null;
        if ($event->providerOrderId !== null) {
            $payment = $this->payments->findByProviderOrderId($event->provider, $event->providerOrderId);
        }

        if ($payment === null) {
            $this->transactions->run(function () use ($event, $workerId, $now): void {
                $fresh = $this->requireLease($event, $workerId);
                $this->webhookEvents->markRetryOrDead(
                    $fresh->webhookEventId,
                    $workerId,
                    (string) $fresh->leaseToken,
                    $fresh->rowVersion,
                    'missing_payment',
                    $now,
                    $this->maxAttempts,
                    $this->backoffBaseSeconds,
                    $this->backoffCapSeconds,
                );
            });

            return;
        }

        if (RazorpayWebhookEventTypes::isCaptureSuccess($event->eventType)) {
            $provider = $this->resolveProviderResult($event, $payment->amountMinor, $payment->currency);
            $this->transactions->run(function () use ($event, $workerId, $payment, $provider, $now): void {
                $fresh = $this->requireLease($event, $workerId);
                if ($event->amountMinor !== null && $event->amountMinor !== $payment->amountMinor) {
                    $current = $this->payments->findByIdForUpdate($payment->paymentId);
                    if ($current !== null && $current->status === PaymentStatus::PENDING) {
                        $this->paymentStateMachine->assertCanTransition(
                            PaymentStatus::PENDING,
                            PaymentStatus::RECONCILIATION_PENDING,
                            ['system'],
                        );
                        $applied = $this->payments->applyTransition(
                            $current->paymentId,
                            PaymentStatus::PENDING,
                            PaymentStatus::RECONCILIATION_PENDING,
                            $current->rowVersion,
                            $now,
                            null,
                            'amount_mismatch',
                            $event->providerPaymentId,
                            null,
                        );
                        if ($applied) {
                            $this->paymentHistory->append(new PaymentStatusHistoryWrite(
                                paymentId: $current->paymentId,
                                applicationId: $current->applicationId,
                                statusBefore: PaymentStatus::PENDING,
                                statusAfter: PaymentStatus::RECONCILIATION_PENDING,
                                source: 'webhook',
                                providerEventReference: $event->providerEventId,
                                reason: 'amount_mismatch',
                                failureCategory: 'amount_mismatch',
                                actorUserId: null,
                                createdAt: $now,
                            ));
                        }
                    }
                    $this->finishProcessed($fresh, $workerId, $now);

                    return;
                }

                $this->acceptance->accept(
                    $payment->paymentId,
                    $provider,
                    'webhook',
                    $event->providerEventId,
                );
                $this->finishProcessed($fresh, $workerId, $now);
                $this->audit->record(
                    new PaymentAuditPayload(
                        action: 'payment.webhook_processed',
                        entityType: 'payment_webhook_event',
                        entityId: (string) $fresh->webhookEventId,
                        next: [
                            'webhook_event_id' => $fresh->webhookEventId,
                            'payment_id' => $payment->paymentId,
                            'event_type' => $fresh->eventType,
                            'result' => 'processed',
                        ],
                    ),
                    actorType: 'system',
                    actorUserId: null,
                    source: 'webhook',
                );
            });

            return;
        }

        if ($event->eventType === RazorpayWebhookEventTypes::PAYMENT_FAILED) {
            $this->transactions->run(function () use ($event, $workerId, $payment, $now): void {
                $fresh = $this->requireLease($event, $workerId);
                $locked = $this->payments->findByIdForUpdate($payment->paymentId);
                if ($locked !== null
                    && $locked->status === PaymentStatus::PENDING
                    && $locked->successfulMarker === null
                ) {
                    $this->paymentStateMachine->assertCanTransition(
                        PaymentStatus::PENDING,
                        PaymentStatus::FAILED,
                        ['system'],
                        $event->failureCode ?? 'payment_failed',
                    );
                    $applied = $this->payments->applyTransition(
                        $locked->paymentId,
                        PaymentStatus::PENDING,
                        PaymentStatus::FAILED,
                        $locked->rowVersion,
                        $now,
                        $event->failureCode,
                        $event->failureCategory ?? 'gateway_declined',
                        $event->providerPaymentId,
                        null,
                    );
                    if ($applied) {
                        $this->paymentHistory->append(new PaymentStatusHistoryWrite(
                            paymentId: $locked->paymentId,
                            applicationId: $locked->applicationId,
                            statusBefore: PaymentStatus::PENDING,
                            statusAfter: PaymentStatus::FAILED,
                            source: 'webhook',
                            providerEventReference: $event->providerEventId,
                            reason: $event->failureCode ?? 'payment_failed',
                            failureCategory: $event->failureCategory ?? 'gateway_declined',
                            actorUserId: null,
                            createdAt: $now,
                        ));
                    }
                }
                // Delayed failed after successful: no-op (terminal success preserved).
                $this->finishProcessed($fresh, $workerId, $now);
            });

            return;
        }

        $this->transactions->run(function () use ($event, $workerId, $now): void {
            $this->finishIgnored($event, $workerId, 'unhandled_edge', $now);
        });
    }

    private function resolveProviderResult(
        PaymentWebhookEvent $event,
        int $fallbackAmount,
        string $fallbackCurrency,
    ): GatewayPaymentResult {
        if ($event->providerPaymentId !== null && $event->providerPaymentId !== '') {
            try {
                return $this->gateway->fetchPayment($event->providerPaymentId);
            } catch (Throwable) {
                // Fall through to webhook-normalized fields when provider fetch unavailable (tests/fake).
            }
        }

        return new GatewayPaymentResult(
            providerPaymentId: $event->providerPaymentId ?? ('unknown_' . $event->webhookEventId),
            providerOrderId: $event->providerOrderId,
            amountMinor: $event->amountMinor ?? $fallbackAmount,
            currency: $event->currency ?? $fallbackCurrency,
            providerStatus: $event->providerStatus ?? 'captured',
            captured: ($event->capturedFlag ?? 1) === 1,
            failureCode: $event->failureCode,
            failureCategory: $event->failureCategory,
        );
    }

    private function requireLease(PaymentWebhookEvent $event, string $workerId): PaymentWebhookEvent
    {
        $fresh = $this->webhookEvents->findById($event->webhookEventId);
        if ($fresh === null
            || $fresh->leaseOwner !== $workerId
            || $fresh->leaseToken === null
        ) {
            throw new ConflictException('Webhook lease lost.');
        }

        return $fresh;
    }

    private function finishProcessed(PaymentWebhookEvent $event, string $workerId, DateTimeImmutable $now): void
    {
        $ok = $this->webhookEvents->markProcessed(
            $event->webhookEventId,
            $workerId,
            (string) $event->leaseToken,
            $event->rowVersion,
            $now,
        );
        if (!$ok) {
            throw new ConflictException('Webhook processed mark lost fencing.');
        }
    }

    private function finishIgnored(
        PaymentWebhookEvent $event,
        string $workerId,
        string $reason,
        DateTimeImmutable $now,
    ): void {
        $fresh = $this->requireLease($event, $workerId);
        $ok = $this->webhookEvents->markIgnored(
            $fresh->webhookEventId,
            $workerId,
            (string) $fresh->leaseToken,
            $fresh->rowVersion,
            $reason,
            $now,
        );
        if (!$ok) {
            throw new ConflictException('Webhook ignored mark lost fencing.');
        }
    }
}
