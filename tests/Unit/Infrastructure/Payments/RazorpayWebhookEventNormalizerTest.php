<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Payments;

use Academy\Infrastructure\Payments\RazorpayWebhookEventNormalizer;
use PHPUnit\Framework\TestCase;

final class RazorpayWebhookEventNormalizerTest extends TestCase
{
    public function testNormalizesCapturedPayment(): void
    {
        $payload = [
            'id' => 'evt_abc',
            'event' => 'payment.captured',
            'created_at' => 1_700_000_000,
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_1',
                        'order_id' => 'order_1',
                        'amount' => 1180000,
                        'currency' => 'INR',
                        'status' => 'captured',
                        'captured' => true,
                    ],
                ],
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $normalized = (new RazorpayWebhookEventNormalizer())->normalize($payload, $raw);

        self::assertTrue($normalized->supported);
        self::assertSame('evt_abc', $normalized->providerEventId);
        self::assertSame('pay_1', $normalized->providerPaymentId);
        self::assertSame('order_1', $normalized->providerOrderId);
        self::assertSame(1180000, $normalized->amountMinor);
        self::assertSame(hash('sha256', $raw), $normalized->payloadDigest);
    }
}
