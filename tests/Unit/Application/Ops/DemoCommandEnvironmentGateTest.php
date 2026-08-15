<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Ops;

use Academy\Application\Ops\DemoPrepareService;
use Academy\Application\Ops\DemoProcessService;
use Academy\Application\Ops\EnvironmentCapability;
use Academy\Application\Ops\UatSeedService;
use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\Payments\PaymentReconciliationService;
use Academy\Application\Payments\PaymentWebhookProcessor;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Tests\Support\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DemoCommandEnvironmentGateTest extends TestCase
{
    public function testPrepareRefusesProduction(): void
    {
        $service = new DemoPrepareService(
            EnvironmentCapability::fromEnvName('production'),
            new UatSeedService($this->unusedConnections(), EnvironmentCapability::fromEnvName('production')),
            '/tmp',
            'http://example.test',
            true,
            true,
            'local',
            'local_file',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refused');
        $service->prepare(true);
    }

    public function testPrepareRequiresConfirm(): void
    {
        $service = new DemoPrepareService(
            EnvironmentCapability::fromEnvName('local'),
            new UatSeedService($this->unusedConnections(), EnvironmentCapability::fromEnvName('local')),
            '/tmp',
            'http://example.test',
            true,
            true,
            'local',
            'local_file',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--confirm');
        $service->prepare(false);
    }

    public function testProcessRefusesStaging(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        putenv('PAYMENTS_FAKE_GATEWAY=1');
        $_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
        $container = ApplicationFactory::container('testing');

        $service = new DemoProcessService(
            EnvironmentCapability::fromEnvName('staging'),
            $container->get(DocumentScanWorker::class),
            $container->get(OutboxRelayService::class),
            $container->get(PaymentWebhookProcessor::class),
            $container->get(PaymentReconciliationService::class),
            $container->get(IdentityNotificationDeliveryWorker::class),
            $container->get(TransactionalNotificationDeliveryWorker::class),
        );

        $this->expectException(RuntimeException::class);
        $service->process('w');
    }

    private function unusedConnections(): ConnectionFactory
    {
        return new ConnectionFactory([
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
            'charset' => 'utf8mb4',
            'options' => [],
        ]);
    }
}
