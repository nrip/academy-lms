<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Ops;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Application\Ops\EnvironmentValidator;
use PHPUnit\Framework\TestCase;

final class EnvironmentValidatorTest extends TestCase
{
    public function testProductionLikeRejectsFakeGatewayWithoutEchoingSecrets(): void
    {
        $config = $this->baseConfig('production');
        $config['security']['payments']['fake_gateway_enabled'] = true;
        $config['security']['payments']['razorpay_key_secret'] = 'super-secret-value-xyz';

        $result = (new EnvironmentValidator())->validate(
            $config,
            EnvironmentCapability::fromEnvName('production'),
        );

        self::assertFalse($result->ok());
        $joined = implode(' ', $result->errors());
        self::assertStringContainsString('PAYMENTS_FAKE_GATEWAY', $joined);
        self::assertStringNotContainsString('super-secret-value-xyz', $joined);
    }

    public function testUatAllowsExplicitFakeGatewayWhenConfigured(): void
    {
        $config = $this->baseConfig('uat');
        $config['security']['payments']['fake_gateway_enabled'] = true;
        $config['security']['documents']['storage_driver'] = 'local';
        $config['security']['documents']['local_signing_secret'] = 'uat-signing-secret';
        $config['security']['notifications']['email_adapter'] = 'local_file';

        $result = (new EnvironmentValidator())->validate(
            $config,
            EnvironmentCapability::fromEnvName('uat'),
        );

        self::assertTrue($result->ok(), implode('; ', $result->errors()));
    }

    public function testMissingRequiredKeysProduceActionableMessages(): void
    {
        $config = $this->baseConfig('uat');
        $config['security']['rate_limit_pepper'] = '';
        $config['security']['identity_tokens']['token_pepper'] = '';

        $result = (new EnvironmentValidator())->validate(
            $config,
            EnvironmentCapability::fromEnvName('uat'),
        );

        self::assertFalse($result->ok());
        $joined = implode(' ', $result->errors());
        self::assertStringContainsString('RATE_LIMIT_PEPPER', $joined);
        self::assertStringContainsString('TOKEN_PEPPER', $joined);
    }

    public function testLocalEnvFailsClosedWhenPhpUploadLimitsAreInadequate(): void
    {
        $report = \Academy\Application\Credentials\PhpUploadRuntimeGuard::inspect(
            \Academy\Domain\Credentials\DocumentFileValidator::PLATFORM_MAX_BYTES,
        );
        if ($report['adequate']) {
            self::markTestSkipped('PHP limits already meet the 10 MB application cap.');
        }

        $result = (new EnvironmentValidator())->validate(
            $this->baseConfig('local'),
            EnvironmentCapability::fromEnvName('local'),
        );
        self::assertFalse($result->ok());
        self::assertStringContainsString('upload', implode(' ', $result->errors()));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(string $env): array
    {
        $root = dirname(__DIR__, 4);
        $storage = $root . '/storage';
        if (!is_dir($storage)) {
            mkdir($storage, 0775, true);
        }
        $logDir = $storage . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        return [
            'app' => [
                'name' => 'Academy LMS',
                'env' => $env,
                'debug' => false,
                'url' => 'http://localhost:8080',
            ],
            'database' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'academy_lms',
                'user' => 'academy',
                'password' => 'secret-db-password',
                'charset' => 'utf8mb4',
            ],
            'logging' => [
                'name' => 'academy',
                'level' => 'info',
                'path' => $logDir . '/test.log',
                'json' => true,
            ],
            'security' => [
                'rate_limit_pepper' => 'pepper',
                'identity_tokens' => [
                    'token_pepper' => 'token-pepper',
                    'otp_pepper' => 'otp-pepper',
                ],
                'notifications' => [
                    'delivery_key' => base64_encode(str_repeat('a', 32)),
                    'email_adapter' => 'unavailable',
                    'sms_adapter' => 'unavailable',
                ],
                'documents' => [
                    'storage_driver' => 'unconfigured',
                    'fake_scanner_enabled' => false,
                    'local_signing_secret' => '',
                    'local_base_path' => 'storage/documents',
                ],
                'payments' => [
                    'fake_gateway_enabled' => false,
                    'razorpay_key_id' => '',
                    'razorpay_key_secret' => '',
                    'razorpay_webhook_secret' => '',
                ],
            ],
            'paths' => [
                'root' => $root,
                'templates' => $root . '/templates',
                'storage' => $storage,
                'public' => $root . '/public',
            ],
        ];
    }
}
