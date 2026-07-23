<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

interface WebhookSignatureVerifier
{
    public function verify(string $rawBody, string $signatureHeader): bool;
}
