<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Payments;

use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Payments\PaymentProvider;
use Academy\Infrastructure\Payments\RazorpayPaymentGateway;
use PHPUnit\Framework\TestCase;

final class RazorpayPaymentGatewayTest extends TestCase
{
    public function testCreateOrderPostsPayloadAndMapsResponse(): void
    {
        /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> $calls */
        $calls = [];
        $gateway = new RazorpayPaymentGateway(
            'rzp_test_key',
            'rzp_test_secret',
            static function (string $method, string $url, array $headers, ?string $body) use (&$calls): string {
                $calls[] = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $body,
                ];

                return json_encode([
                    'id' => 'order_RzpUnitTest001',
                    'amount' => 125000,
                    'currency' => 'INR',
                    'status' => 'created',
                ], JSON_THROW_ON_ERROR);
            },
        );

        $order = $gateway->createOrder(
            125000,
            'inr',
            'PAY-APP-1',
            ['application_id' => '1', 'payment_id' => '9'],
            'pay:app:1:attempt:1',
        );

        self::assertSame(PaymentProvider::RAZORPAY, $gateway->provider());
        self::assertSame('rzp_test_key', $gateway->publicKeyId());
        self::assertSame('order_RzpUnitTest001', $order->providerOrderId);
        self::assertSame(125000, $order->amountMinor);
        self::assertSame('INR', $order->currency);
        self::assertSame('created', $order->providerStatus);

        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.razorpay.com/v1/orders', $call['url']);
        self::assertNotNull($call['body']);
        $decoded = json_decode((string) $call['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(125000, $decoded['amount']);
        self::assertSame('INR', $decoded['currency']);
        self::assertSame('PAY-APP-1', $decoded['receipt']);
        self::assertSame(['application_id' => '1', 'payment_id' => '9'], $decoded['notes']);

        $headerBlob = implode("\n", $call['headers']);
        self::assertStringContainsString('Idempotency-Key: pay:app:1:attempt:1', $headerBlob);
        self::assertStringContainsString('Authorization: Basic ', $headerBlob);
        // Secret must not appear in plaintext in headers or body (Basic auth is base64(key:secret)).
        self::assertStringNotContainsString('rzp_test_secret', $headerBlob);
        self::assertStringNotContainsString('rzp_test_secret', (string) $call['body']);
        self::assertStringNotContainsString('rzp_test_secret', $order->providerOrderId);
    }

    public function testCreateOrderSurfacesProviderErrorWithoutLeakingSecrets(): void
    {
        $gateway = new RazorpayPaymentGateway(
            'rzp_test_key',
            'super-secret-value-xyz',
            static function (string $method, string $url, array $headers, ?string $body): string {
                return json_encode([
                    'error' => [
                        'code' => 'BAD_REQUEST_ERROR',
                        'description' => 'Amount is required',
                    ],
                ], JSON_THROW_ON_ERROR);
            },
        );

        try {
            $gateway->createOrder(100, 'INR', 'r1', [], 'idem-1');
            self::fail('Expected ExternalServiceException');
        } catch (ExternalServiceException $e) {
            self::assertStringContainsString('Razorpay', $e->getMessage());
            self::assertStringNotContainsString('super-secret-value-xyz', $e->getMessage());
        }
    }

    public function testConstructorRejectsIncompleteCredentials(): void
    {
        $this->expectException(ExternalServiceException::class);
        new RazorpayPaymentGateway('rzp_test_key', '  ');
    }
}
