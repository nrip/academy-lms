<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

final class PaymentOutboxEventTypes
{
    public const ATTEMPT_CREATED = 'payment.attempt_created';
    public const GATEWAY_ORDER_BOUND = 'payment.gateway_order_bound';
    public const FAILED = 'payment.failed';
}
