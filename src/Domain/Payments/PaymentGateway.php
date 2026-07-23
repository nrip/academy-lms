<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

/**
 * Provider-neutral gateway contract. Mode A activates Razorpay only.
 * WP-05 uses createOrder (+ fetch for Fake testing). Webhook verify is WP-06.
 */
interface PaymentGateway
{
    public function provider(): string;

    /**
     * @param array<string, scalar|null> $notes Safe local identifiers only
     */
    public function createOrder(
        int $amountMinor,
        string $currency,
        string $receipt,
        array $notes,
        string $idempotencyKey,
    ): GatewayOrderResult;

    public function fetchOrder(string $providerOrderId): GatewayOrderResult;

    public function publicKeyId(): string;
}
