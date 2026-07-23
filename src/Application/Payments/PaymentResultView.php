<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Payments\Payment;

/**
 * A-09 payment result / confirming view. Browser return is informational only.
 */
final class PaymentResultView
{
    /**
     * @param list<Payment> $attempts
     */
    public function __construct(
        public readonly Application $application,
        public readonly ?Payment $primaryPayment,
        public readonly array $attempts,
        public readonly bool $isConfirming,
        public readonly string $statusHeadline,
        public readonly ?string $enrolmentLifecycleLabel = null,
    ) {
    }
}
