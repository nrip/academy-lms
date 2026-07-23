<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Payments;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Payments\Webhook\WebhookSignatureVerifier;

final class RazorpayWebhookSignatureVerifier implements WebhookSignatureVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
    ) {
    }

    public function verify(string $rawBody, string $signatureHeader): bool
    {
        if (trim($this->webhookSecret) === '') {
            throw new ExternalServiceException('Razorpay webhook secret is not configured.');
        }
        if ($signatureHeader === '' || $rawBody === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        return hash_equals($expected, $signatureHeader);
    }
}
