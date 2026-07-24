<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Application\Ops\EnvironmentCapability;
use InvalidArgumentException;

/**
 * Local/testing/UAT helper to produce Razorpay-compatible webhook signatures.
 */
final class FakeWebhookSigner
{
    public function __construct(
        private readonly string $env,
        private readonly bool $enabled,
        private readonly string $webhookSecret,
    ) {
        $capability = EnvironmentCapability::fromEnvName($env);
        if (!$enabled || !$capability->allowsFakeOrLocalAdapters()) {
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
