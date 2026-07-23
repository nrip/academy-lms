<?php

declare(strict_types=1);

namespace Academy\Domain\Payments\Webhook;

final class RazorpayWebhookEventTypes
{
    public const PAYMENT_AUTHORIZED = 'payment.authorized';
    public const PAYMENT_CAPTURED = 'payment.captured';
    public const PAYMENT_FAILED = 'payment.failed';
    public const ORDER_PAID = 'order.paid';

    /** @var list<string> */
    public const HANDLED = [
        self::PAYMENT_AUTHORIZED,
        self::PAYMENT_CAPTURED,
        self::PAYMENT_FAILED,
        self::ORDER_PAID,
    ];

    /** Events that may drive Payment → successful acceptance. */
    /** @var list<string> */
    public const CAPTURE_SUCCESS = [
        self::PAYMENT_CAPTURED,
        self::ORDER_PAID,
    ];

    public static function isHandled(string $eventType): bool
    {
        return in_array($eventType, self::HANDLED, true);
    }

    public static function isCaptureSuccess(string $eventType): bool
    {
        return in_array($eventType, self::CAPTURE_SUCCESS, true);
    }
}
