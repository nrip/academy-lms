<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Payments\GatewayOrderResult;
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

    public function publicKeyId(): string
    {
        return 'rzp_test_fake_public_key';
    }
}
