<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Payments\GatewayOrderResult;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentProvider;

final class UnconfiguredPaymentGateway implements PaymentGateway
{
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
        throw new ExternalServiceException('Payment gateway is not configured.');
    }

    public function fetchOrder(string $providerOrderId): GatewayOrderResult
    {
        throw new ExternalServiceException('Payment gateway is not configured.');
    }

    public function publicKeyId(): string
    {
        throw new ExternalServiceException('Payment gateway is not configured.');
    }
}
