<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Ops;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Application\Ops\ReadinessProbe;
use Academy\Application\Ops\UatResetService;
use Academy\Application\Ops\UatSeedService;
use Academy\Infrastructure\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UatEnvironmentGateTest extends TestCase
{
    public function testSeedRefusedInProduction(): void
    {
        $service = new UatSeedService(
            $this->unusedConnections(),
            EnvironmentCapability::fromEnvName('production'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uat:seed refused');
        $service->seed();
    }

    public function testResetRefusedInStaging(): void
    {
        $service = new UatResetService(
            $this->unusedConnections(),
            EnvironmentCapability::fromEnvName('staging'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uat:reset refused');
        $service->reset(true);
    }

    public function testResetRequiresConfirmInUat(): void
    {
        $service = new UatResetService(
            $this->unusedConnections(),
            EnvironmentCapability::fromEnvName('uat'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--confirm');
        $service->reset(false);
    }

    public function testReadinessMapsNotReadyWhenDbUnavailable(): void
    {
        $root = dirname(__DIR__, 4);
        $storage = $root . '/storage';
        $config = [
            'app' => ['env' => 'testing', 'debug' => false, 'url' => 'http://localhost'],
            'database' => [
                'host' => '127.0.0.1',
                'port' => 65534,
                'name' => 'no_such_db',
                'user' => 'nobody',
                'password' => 'secret-should-not-leak',
                'charset' => 'utf8mb4',
            ],
            'logging' => ['path' => $storage . '/logs/ready-test.log'],
            'security' => [
                'rate_limit_pepper' => 'p',
                'identity_tokens' => ['token_pepper' => 't', 'otp_pepper' => 'o'],
                'notifications' => [
                    'delivery_key' => base64_encode(str_repeat('b', 32)),
                    'email_adapter' => 'recording',
                    'sms_adapter' => 'recording',
                ],
                'documents' => [
                    'storage_driver' => 'local',
                    'local_base_path' => $storage . '/documents',
                    'local_signing_secret' => 's',
                    'fake_scanner_enabled' => true,
                ],
                'payments' => ['fake_gateway_enabled' => true],
            ],
            'paths' => ['storage' => $storage],
        ];

        $result = (new ReadinessProbe())->probe(
            $config,
            EnvironmentCapability::fromEnvName('testing'),
            null,
            true,
        );

        self::assertFalse($result['ready']);
        self::assertSame('not_ready', $result['status']);
        self::assertSame('fail', $result['checks']['database']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-should-not-leak', $encoded);
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
