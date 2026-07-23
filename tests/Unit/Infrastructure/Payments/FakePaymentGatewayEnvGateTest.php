<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Payments;

use Academy\Infrastructure\Payments\FakePaymentGateway;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FakePaymentGatewayEnvGateTest extends TestCase
{
    #[DataProvider('forbiddenEnvironments')]
    public function testDeniedOutsideLocalTestingCi(string $env): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakePaymentGateway($env, true);
    }

    public function testDeniedWhenDisabledEvenInTesting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakePaymentGateway('testing', false);
    }

    #[DataProvider('allowedEnvironments')]
    public function testAllowedInLocalTestingCiWhenEnabled(string $env): void
    {
        $gateway = new FakePaymentGateway($env, true);
        self::assertSame('rzp_test_fake_public_key', $gateway->publicKeyId());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function forbiddenEnvironments(): array
    {
        return [
            ['staging'],
            ['production'],
            ['demo'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function allowedEnvironments(): array
    {
        return [
            ['local'],
            ['testing'],
            ['ci'],
        ];
    }
}
