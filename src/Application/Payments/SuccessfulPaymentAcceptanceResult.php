<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Domain\Learning\Enrolment;
use Academy\Domain\Payments\Payment;

final class SuccessfulPaymentAcceptanceResult
{
    public const ACCEPTED = 'accepted';
    public const DUPLICATE = 'duplicate';
    public const CAPACITY_EXHAUSTED = 'capacity_exhausted';
    public const IDEMPOTENT = 'idempotent';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $outcome,
        public readonly Payment $payment,
        public readonly ?Enrolment $enrolment = null,
        public readonly ?string $detail = null,
    ) {
    }
}
