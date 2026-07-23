<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use DateTimeImmutable;

final class PaymentTransitionResult
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly DateTimeImmutable $transitionedAt,
        public readonly ?string $reason = null,
    ) {
    }
}
