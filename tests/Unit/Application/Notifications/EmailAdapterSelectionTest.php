<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Notifications;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Infrastructure\Notifications\RecordingEmailAdapter;
use Academy\Infrastructure\Notifications\SmtpEmailAdapter;
use Academy\Tests\Support\ApplicationFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailAdapterSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'MAIL_DRIVER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
            'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_ENCRYPTION', 'NOTIFICATION_EMAIL_ADAPTER',
            'NOTIFICATION_SMS_ADAPTER',
        ] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    public function testMailDriverSmtpResolvesToSmtpInSecurityConfig(): void
    {
        $config = $this->buildSecurity('local', [
            'MAIL_DRIVER' => 'smtp',
            'MAIL_HOST' => 'email-smtp.example.test',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test',
            'MAIL_FROM_NAME' => 'Academy LMS',
            'NOTIFICATION_EMAIL_ADAPTER' => 'local_file',
            'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
        ]);

        self::assertSame('smtp', $config['notifications']['email_adapter']);
        self::assertSame('email-smtp.example.test', $config['notifications']['mail']['host']);
        self::assertSame('noreply@example.test', $config['notifications']['mail']['from_address']);
    }

    public function testMailDriverSesAliasesToSmtpAdapter(): void
    {
        $config = $this->buildSecurity('uat', [
            'MAIL_DRIVER' => 'ses',
            'MAIL_HOST' => 'email-smtp.ap-south-1.amazonaws.com',
            'MAIL_PORT' => '587',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test',
            'NOTIFICATION_EMAIL_ADAPTER' => 'unavailable',
            'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
        ]);

        self::assertSame('smtp', $config['notifications']['email_adapter']);
        self::assertSame('tls', $config['notifications']['mail']['encryption']);
    }

    public function testRecordingRemainsWhenMailDriverUnset(): void
    {
        $config = $this->buildSecurity('testing', [
            'NOTIFICATION_EMAIL_ADAPTER' => 'recording',
            'NOTIFICATION_SMS_ADAPTER' => 'recording',
        ]);

        self::assertSame('recording', $config['notifications']['email_adapter']);
    }

    public function testStagingRejectsRecordingAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recording/local email adapters are forbidden');
        $this->buildSecurity('staging', [
            'NOTIFICATION_EMAIL_ADAPTER' => 'recording',
            'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
        ]);
    }

    public function testStagingRejectsLocalFileAdapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recording/local email adapters are forbidden');
        $this->buildSecurity('production', [
            'NOTIFICATION_EMAIL_ADAPTER' => 'local_file',
            'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
        ]);
    }

    public function testTestingContainerUsesRecordingPort(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('NOTIFICATION_EMAIL_ADAPTER=recording');
        $_ENV['NOTIFICATION_EMAIL_ADAPTER'] = 'recording';
        putenv('MAIL_DRIVER');
        unset($_ENV['MAIL_DRIVER'], $_SERVER['MAIL_DRIVER']);

        $port = ApplicationFactory::container('testing')->get(EmailDeliveryPort::class);
        self::assertInstanceOf(RecordingEmailAdapter::class, $port);
    }

    public function testSmtpAdapterCanBeConstructedFromMailConfig(): void
    {
        $config = $this->buildSecurity('local', [
            'MAIL_DRIVER' => 'smtp',
            'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '465',
            'MAIL_FROM_ADDRESS' => 'noreply@example.test',
            'MAIL_USERNAME' => 'user',
            'MAIL_PASSWORD' => 'secret',
        ]);
        $mail = $config['notifications']['mail'];
        $adapter = new SmtpEmailAdapter(
            $mail['host'],
            $mail['port'],
            $mail['username'],
            $mail['password'],
            $mail['from_address'],
            $mail['from_name'],
            $mail['encryption'],
        );
        self::assertInstanceOf(SmtpEmailAdapter::class, $adapter);
        self::assertSame('ssl', $mail['encryption']);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function buildSecurity(string $envName, array $env): array
    {
        $defaults = [
            'RATE_LIMIT_PEPPER' => 'rate-pepper-value',
            'TOKEN_PEPPER' => 'token-pepper-value',
            'OTP_PEPPER' => 'otp-pepper-value-x',
            'NOTIFICATION_DELIVERY_KEY' => base64_encode(str_repeat('a', 32)),
            'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
            'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
        ];
        $map = array_merge($defaults, $env);

        $string = static function (string $key, string $default = '') use ($map): string {
            return $map[$key] ?? $default;
        };
        $bool = static fn (string $key, bool $default): bool => $default;
        $int = static function (string $key, int $default) use ($string): int {
            $value = $string($key, '');

            return $value === '' ? $default : (int) $value;
        };

        /** @var callable(string, callable, callable, callable, ?EnvironmentCapability): array $builder */
        $builder = require dirname(__DIR__, 4) . '/config/security.php';

        return $builder($envName, $bool, $string, $int, EnvironmentCapability::fromEnvName($envName));
    }
}
