<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\Webhook\PaymentWebhookEvent;
use Academy\Domain\Payments\Webhook\PaymentWebhookEventRepository;
use Academy\Domain\Security\AuthContext;

final class FinanceReconciliationQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly PaymentRepository $payments,
        private readonly PaymentStatusHistoryRepository $history,
        private readonly PaymentWebhookEventRepository $webhookEvents,
    ) {
    }

    /**
     * @return array{
     *   payments: list<Payment>,
     *   payment_total: int,
     *   webhook_events: list<PaymentWebhookEvent>,
     *   webhook_total: int
     * }
     */
    public function overview(AuthContext $auth): array
    {
        $this->authorization->require($auth, 'finance.payment.reconcile');

        $payments = $this->payments->listForFinance(
            PaymentStatus::RECONCILIATION_PENDING,
            null,
            null,
            null,
            50,
            0,
        );
        $paymentTotal = $this->payments->countForFinance(
            PaymentStatus::RECONCILIATION_PENDING,
            null,
            null,
            null,
        );
        $events = $this->webhookEvents->listForFinance('failed', 50, 0);
        $dead = $this->webhookEvents->listForFinance('dead', 50, 0);
        $webhookEvents = array_merge($events, $dead);

        return [
            'payments' => $payments,
            'payment_total' => $paymentTotal,
            'webhook_events' => $webhookEvents,
            'webhook_total' => $this->webhookEvents->countForFinance('failed')
                + $this->webhookEvents->countForFinance('dead'),
        ];
    }

    /**
     * @return array{payment: Payment, history: list<array<string, mixed>>}
     */
    public function paymentDetail(AuthContext $auth, int $paymentId): array
    {
        $this->authorization->require($auth, 'finance.payment.reconcile');
        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            throw new NotFoundException('Payment not found.');
        }

        return [
            'payment' => $payment,
            'history' => $this->history->listByPaymentId($paymentId),
        ];
    }
}
