<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

/**
 * Authoritative provider payment snapshot used after external fetch / webhook normalize.
 */
final class GatewayPaymentResult
{
    public function __construct(
        public readonly string $providerPaymentId,
        public readonly ?string $providerOrderId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $providerStatus,
        public readonly bool $captured,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureCategory = null,
    ) {
    }

    public function isCapturedSuccess(): bool
    {
        return $this->captured
            || in_array(strtolower($this->providerStatus), ['captured', 'paid'], true);
    }

    public function isFailed(): bool
    {
        return in_array(strtolower($this->providerStatus), ['failed', 'cancelled'], true);
    }
}
