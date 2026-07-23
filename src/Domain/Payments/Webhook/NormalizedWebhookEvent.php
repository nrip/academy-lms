<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

use DateTimeImmutable;

/**
 * Safe, allow-listed normalized provider event. Never stores raw card/UPI data.
 */
final class NormalizedWebhookEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly ?string $providerOrderId,
        public readonly ?string $providerPaymentId,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $providerStatus,
        public readonly ?bool $captured,
        public readonly ?string $failureCode,
        public readonly ?string $failureCategory,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly string $payloadDigest,
        public readonly bool $supported,
    ) {
    }
}
