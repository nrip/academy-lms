<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

final class GatewayOrderResult
{
    public function __construct(
        public readonly string $providerOrderId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $providerStatus,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureCategory = null,
    ) {
    }
}
