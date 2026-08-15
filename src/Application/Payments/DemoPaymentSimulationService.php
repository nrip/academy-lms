<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use RuntimeException;

/**
 * Demo-only payment capture simulation.
 *
 * Creates a signed Razorpay-shaped webhook and passes it through
 * RazorpayWebhookIngressService. Never marks Payment successful from the browser.
 * Gated to FakePaymentGateway + non-production-like environments.
 */
final class DemoPaymentSimulationService
{
    public function __construct(
        private readonly EnvironmentCapability $capability,
        private readonly bool $fakeGatewayEnabled,
        private readonly PaymentCheckoutService $checkout,
        private readonly PaymentRepository $payments,
        private readonly PaymentGateway $gateway,
        private readonly FakeWebhookSigner $signer,
        private readonly RazorpayWebhookIngressService $ingress,
        private readonly PaymentWebhookProcessor $webhookProcessor,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->capability->allowsFakeOrLocalAdapters()
            && $this->fakeGatewayEnabled
            && $this->gateway instanceof FakePaymentGateway;
    }

    /**
     * Browser path: record checkout return (confirming) + ingress webhook.
     * Does not run the webhook processor — presenter runs demo:process next.
     *
     * @return array{payment_id: int, webhook_event_id: int, duplicate: bool, confirming: true}
     */
    public function simulateBrowserCapture(AuthContext $auth, int $applicationId, int $paymentId): array
    {
        $this->assertAvailable();
        $payment = $this->checkout->getPayment($auth, $applicationId, $paymentId);
        $this->assertPendingPayment($payment);

        try {
            $this->checkout->recordCheckoutReturn($auth, $applicationId, $paymentId);
        } catch (ConflictException) {
            // Already recorded — still continue with webhook ingress.
        }

        $ingress = $this->ingressCaptureWebhook($payment, 'demo_browser');

        return [
            'payment_id' => $payment->paymentId,
            'webhook_event_id' => $ingress['webhook_event_id'],
            'duplicate' => $ingress['duplicate'],
            'confirming' => true,
        ];
    }

    /**
     * CLI path: ingress webhook and optionally process immediately.
     *
     * @return array{
     *   payment_id: int,
     *   webhook_event_id: int,
     *   duplicate: bool,
     *   processed: int
     * }
     */
    public function simulateCliCapture(int $paymentId, bool $process = true, string $workerId = 'demo-cli'): array
    {
        $this->assertAvailable();
        $payment = $this->payments->findById($paymentId);
        if ($payment === null) {
            throw new NotFoundException('Payment not found.');
        }
        $this->assertPendingPayment($payment);

        $ingress = $this->ingressCaptureWebhook($payment, 'demo_cli');
        $processed = 0;
        if ($process) {
            $processed = $this->webhookProcessor->run($workerId, 25);
        }

        return [
            'payment_id' => $payment->paymentId,
            'webhook_event_id' => $ingress['webhook_event_id'],
            'duplicate' => $ingress['duplicate'],
            'processed' => $processed,
        ];
    }

    /**
     * @return array{duplicate: bool, webhook_event_id: int}
     */
    private function ingressCaptureWebhook(Payment $payment, string $sourceTag): array
    {
        if ($payment->providerOrderId === null || $payment->providerOrderId === '') {
            throw new DomainRuleException('Payment has no provider order binding.');
        }

        if ($this->gateway instanceof FakePaymentGateway) {
            try {
                $this->gateway->rememberOrder(
                    $payment->providerOrderId,
                    $payment->amountMinor,
                    $payment->currency,
                );
                $this->gateway->simulateCapture(
                    $payment->providerOrderId,
                    $payment->amountMinor,
                    $payment->currency,
                    'pay_demo_' . $payment->paymentId,
                );
            } catch (ExternalServiceException) {
                // Processor can fall back to webhook fields if in-memory state is incomplete.
            }
        }

        $eventId = 'evt_demo_' . $sourceTag . '_' . $payment->paymentId . '_' . time();
        $providerPaymentId = 'pay_demo_' . $payment->paymentId;
        $payload = [
            'id' => $eventId,
            'event' => 'payment.captured',
            'created_at' => time(),
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $providerPaymentId,
                        'order_id' => $payment->providerOrderId,
                        'amount' => $payment->amountMinor,
                        'currency' => $payment->currency,
                        'status' => 'captured',
                        'captured' => true,
                    ],
                ],
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $this->signer->sign($raw);

        return $this->ingress->receive($raw, $signature, 'application/json');
    }

    private function assertPendingPayment(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::PENDING && $payment->status !== PaymentStatus::CREATED) {
            throw new ConflictException(
                'Demo capture is only available for pending payment attempts (current: ' . $payment->status . ').',
            );
        }
    }

    private function assertAvailable(): void
    {
        if ($this->capability->isProductionLike()) {
            throw new RuntimeException('Demo payment simulation is forbidden in staging/production.');
        }
        if (!$this->isAvailable()) {
            throw new RuntimeException(
                'Demo payment simulation requires FakePaymentGateway in local|testing|ci|uat with PAYMENTS_FAKE_GATEWAY=1.',
            );
        }
    }
}
