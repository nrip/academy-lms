<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

final class PaymentOutboxEventTypes
{
    public const ATTEMPT_CREATED = 'payment.attempt_created';
    public const GATEWAY_ORDER_BOUND = 'payment.gateway_order_bound';
    public const FAILED = 'payment.failed';
    public const SUCCESSFUL = 'payment.successful';
    public const RECONCILIATION_REQUIRED = 'payment.reconciliation_required';
    public const WEBHOOK_RECEIVED = 'payment.webhook_received';
    public const CAPACITY_EXHAUSTED_AFTER_PAYMENT = 'capacity.exhausted_after_payment';
    public const APPLICATION_ADMITTED = 'application.admitted';
}
