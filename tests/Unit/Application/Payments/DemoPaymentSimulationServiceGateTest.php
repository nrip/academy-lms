<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Payments;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Application\Payments\DemoPaymentSimulationService;
use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Application\Payments\PaymentWebhookProcessor;
use Academy\Application\Payments\RazorpayWebhookIngressService;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use Academy\Tests\Support\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DemoPaymentSimulationServiceGateTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('PAYMENTS_FAKE_GATEWAY=1');
        $_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
    }

    public function testUnavailableWhenFakeGatewayDisabled(): void
    {
        $container = ApplicationFactory::container('testing');
        $service = new DemoPaymentSimulationService(
            EnvironmentCapability::fromEnvName('local'),
            false,
            $container->get(PaymentCheckoutService::class),
            $container->get(PaymentRepository::class),
            $container->get(PaymentGateway::class),
            new FakeWebhookSigner('local', true, 'secret'),
            $container->get(RazorpayWebhookIngressService::class),
            $container->get(PaymentWebhookProcessor::class),
        );

        self::assertFalse($service->isAvailable());
        $this->expectException(RuntimeException::class);
        $service->simulateCliCapture(1, false);
    }

    public function testForbiddenInProductionEvenIfFlagsLie(): void
    {
        $container = ApplicationFactory::container('testing');
        $service = new DemoPaymentSimulationService(
            EnvironmentCapability::fromEnvName('production'),
            true,
            $container->get(PaymentCheckoutService::class),
            $container->get(PaymentRepository::class),
            new FakePaymentGateway('local', true),
            new FakeWebhookSigner('local', true, 'secret'),
            $container->get(RazorpayWebhookIngressService::class),
            $container->get(PaymentWebhookProcessor::class),
        );

        self::assertFalse($service->isAvailable());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden in staging/production');
        $service->simulateCliCapture(1, false);
    }

    public function testAvailableWithFakeGatewayInLocal(): void
    {
        $container = ApplicationFactory::container('testing');
        $service = new DemoPaymentSimulationService(
            EnvironmentCapability::fromEnvName('local'),
            true,
            $container->get(PaymentCheckoutService::class),
            $container->get(PaymentRepository::class),
            new FakePaymentGateway('local', true),
            new FakeWebhookSigner('local', true, 'secret'),
            $container->get(RazorpayWebhookIngressService::class),
            $container->get(PaymentWebhookProcessor::class),
        );

        self::assertTrue($service->isAvailable());
    }
}
