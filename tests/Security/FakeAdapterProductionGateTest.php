<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Infrastructure\Payments\FakePaymentGateway;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FakeAdapterProductionGateTest extends TestCase
{
    public function testFakePaymentGatewayRefusedInProduction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakePaymentGateway('production', true);
    }

    public function testFakePaymentGatewayAllowedInUatWhenEnabled(): void
    {
        $gateway = new FakePaymentGateway('uat', true);
        self::assertSame('razorpay', $gateway->provider());
    }

    public function testFakePaymentGatewayRefusedInUatWhenDisabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakePaymentGateway('uat', false);
    }
}
