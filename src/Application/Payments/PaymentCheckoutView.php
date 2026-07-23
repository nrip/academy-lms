<?php

declare(strict_types=1);

namespace Academy\Application\Payments;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Payments\Payment;
use Academy\Domain\Payments\PaymentAmountSnapshot;

final class PaymentCheckoutView
{
    /**
     * @param list<Payment> $attempts
     */
    public function __construct(
        public readonly Application $application,
        public readonly ?PaymentAmountSnapshot $snapshotPreview,
        public readonly array $attempts,
        public readonly bool $canInitiate,
        public readonly ?string $gatewayPublicKeyId,
    ) {
    }
}
