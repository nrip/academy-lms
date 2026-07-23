<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use InvalidArgumentException;

/**
 * Local/testing helper to produce Razorpay-compatible webhook signatures.
 */
final class FakeWebhookSigner
{
    public function __construct(
        private readonly string $env,
        private readonly bool $enabled,
        private readonly string $webhookSecret,
    ) {
        if (!$enabled || !in_array($env, ['local', 'testing', 'ci'], true)) {
            throw new InvalidArgumentException('FakeWebhookSigner is not permitted in this environment.');
        }
        if (trim($webhookSecret) === '') {
            throw new InvalidArgumentException('FakeWebhookSigner requires a webhook secret.');
        }
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->webhookSecret);
    }

    public function secret(): string
    {
        return $this->webhookSecret;
    }
}
