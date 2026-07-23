<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

/**
 * Provider-neutral gateway contract. Mode A activates Razorpay only.
 * Webhook signature verification is a separate WebhookSignatureVerifier.
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

    public function fetchPayment(string $providerPaymentId): GatewayPaymentResult;

    /**
     * @return list<GatewayPaymentResult>
     */
    public function fetchPaymentsForOrder(string $providerOrderId): array;

    public function publicKeyId(): string;
}
