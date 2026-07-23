<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

interface WebhookEventNormalizer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload, string $rawBody): NormalizedWebhookEvent;
}
