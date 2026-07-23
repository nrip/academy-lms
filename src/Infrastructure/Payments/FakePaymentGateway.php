<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Payments\GatewayOrderResult;
use Academy\Domain\Payments\GatewayPaymentResult;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentProvider;
use InvalidArgumentException;

/**
 * Deterministic fake gateway. Construct only when env is local|testing|ci
 * and the payments fake-gateway flag is enabled.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, GatewayOrderResult> */
    private array $ordersById = [];

    /** @var array<string, GatewayPaymentResult> */
    private array $paymentsById = [];

    /** @var array<string, list<string>> */
    private array $orderPaymentIds = [];

    public function __construct(string $env, bool $enabled)
    {
        if (!$enabled || !in_array($env, ['local', 'testing', 'ci'], true)) {
            throw new InvalidArgumentException('FakePaymentGateway is not permitted in this environment.');
        }
    }

    public function provider(): string
    {
        return PaymentProvider::RAZORPAY;
    }

    public function createOrder(
        int $amountMinor,
        string $currency,
        string $receipt,
        array $notes,
        string $idempotencyKey,
    ): GatewayOrderResult {
        $haystack = strtoupper($receipt . ' ' . json_encode($notes, JSON_THROW_ON_ERROR));
        if (str_contains($haystack, 'FAILORDER')) {
            throw new ExternalServiceException('Fake payment gateway refused order creation (FAILORDER).');
        }

        $orderId = 'order_fake_' . substr(hash('sha256', $idempotencyKey), 0, 14);
        $result = new GatewayOrderResult(
            providerOrderId: $orderId,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            providerStatus: 'created',
        );
        $this->ordersById[$orderId] = $result;

        return $result;
    }

    public function fetchOrder(string $providerOrderId): GatewayOrderResult
    {
        if (!isset($this->ordersById[$providerOrderId])) {
            throw new ExternalServiceException('Fake payment gateway order not found.');
        }

        return $this->ordersById[$providerOrderId];
    }

    public function fetchPayment(string $providerPaymentId): GatewayPaymentResult
    {
        if (!isset($this->paymentsById[$providerPaymentId])) {
            throw new ExternalServiceException('Fake payment gateway payment not found.');
        }

        return $this->paymentsById[$providerPaymentId];
    }

    public function fetchPaymentsForOrder(string $providerOrderId): array
    {
        $ids = $this->orderPaymentIds[$providerOrderId] ?? [];
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->paymentsById[$id])) {
                $out[] = $this->paymentsById[$id];
            }
        }

        return $out;
    }

    public function publicKeyId(): string
    {
        return 'rzp_test_fake_public_key';
    }

    public function simulateCapture(
        string $providerOrderId,
        int $amountMinor,
        string $currency,
        ?string $providerPaymentId = null,
    ): GatewayPaymentResult {
        if (!isset($this->ordersById[$providerOrderId])) {
            throw new ExternalServiceException('Fake payment gateway order not found.');
        }

        $paymentId = $providerPaymentId ?? ('pay_fake_' . substr(hash('sha256', $providerOrderId . microtime(true)), 0, 14));
        $result = new GatewayPaymentResult(
            providerPaymentId: $paymentId,
            providerOrderId: $providerOrderId,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            providerStatus: 'captured',
            captured: true,
        );
        $this->paymentsById[$paymentId] = $result;
        $this->orderPaymentIds[$providerOrderId] ??= [];
        $this->orderPaymentIds[$providerOrderId][] = $paymentId;
        $this->ordersById[$providerOrderId] = new GatewayOrderResult(
            providerOrderId: $providerOrderId,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            providerStatus: 'paid',
        );

        return $result;
    }

    public function simulateFailure(
        string $providerOrderId,
        int $amountMinor,
        string $currency,
        string $failureCode = 'payment_failed',
        ?string $providerPaymentId = null,
    ): GatewayPaymentResult {
        if (!isset($this->ordersById[$providerOrderId])) {
            throw new ExternalServiceException('Fake payment gateway order not found.');
        }

        $paymentId = $providerPaymentId ?? ('pay_fake_fail_' . substr(hash('sha256', $providerOrderId), 0, 10));
        $result = new GatewayPaymentResult(
            providerPaymentId: $paymentId,
            providerOrderId: $providerOrderId,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            providerStatus: 'failed',
            captured: false,
            failureCode: $failureCode,
            failureCategory: 'gateway_declined',
        );
        $this->paymentsById[$paymentId] = $result;
        $this->orderPaymentIds[$providerOrderId] ??= [];
        $this->orderPaymentIds[$providerOrderId][] = $paymentId;

        return $result;
    }
}
