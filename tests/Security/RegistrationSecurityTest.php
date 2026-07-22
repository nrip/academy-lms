<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Identity\MobileOtpVerificationService;
use Academy\Application\Identity\RegistrationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RegistrationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testAuditLogNeverContainsPasswordEmailMobileTokenOrOtpPlaintext(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var RegistrationService $service */
        $service = $container->get(RegistrationService::class);

        $email = 'secaudit.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);
        $password = 'a-super-secret-password-9';

        $result = $service->register($email, $mobile, $password, true, true, '203.0.113.60');
        self::assertTrue($result->created);

        /** @var IdentityNotificationDeliveryWorker $worker */
        $worker = $container->get(IdentityNotificationDeliveryWorker::class);
        $worker->run('registration-security-worker', 10);

        $pdo = DatabaseTestCase::pdo();
        $rows = $pdo->query('SELECT previous_value, new_value, reason FROM audit_log')->fetchAll();
        $joined = json_encode($rows, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($password, $joined);
        self::assertStringNotContainsString(strtolower($email), $joined);
        self::assertStringNotContainsString($mobile, $joined);
        self::assertStringNotContainsString('+91' . $mobile, $joined);
        self::assertStringNotContainsString('argon2id', $joined);
        self::assertStringNotContainsString('delivery_ciphertext', $joined);
        self::assertStringNotContainsString('otp_delivery_ciphertext', $joined);
    }

    public function testOutboxPayloadsForRegistrationCarryNoDestinationsOrSecrets(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var RegistrationService $service */
        $service = $container->get(RegistrationService::class);

        $email = 'secoutbox.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $result = $service->register($email, $mobile, 'a-super-secret-password-10', true, true, '203.0.113.61');
        self::assertTrue($result->created);

        $pdo = DatabaseTestCase::pdo();
        $rows = $pdo->query("SELECT payload FROM outbox_messages WHERE event_type LIKE 'identity.%'")->fetchAll();
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('email', $decoded);
            self::assertArrayNotHasKey('mobile', $decoded);
            self::assertArrayNotHasKey('mobile_e164', $decoded);
            self::assertArrayNotHasKey('otp', $decoded);
            self::assertArrayNotHasKey('link_token', $decoded);
            self::assertArrayNotHasKey('raw_token', $decoded);
        }
    }

    public function testRateLimitBucketKeyNeverContainsEmailOrMobilePlaintext(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var RegistrationService $service */
        $service = $container->get(RegistrationService::class);

        $email = 'secratelimit.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $result = $service->register($email, $mobile, 'a-super-secret-password-11', true, true, '203.0.113.62');
        self::assertTrue($result->created);

        /** @var VerificationChallengeIssuer $issuer */
        $issuer = $container->get(VerificationChallengeIssuer::class);
        $issued = $issuer->issue($result->userId, '+91' . $mobile, new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')));

        /** @var MobileOtpVerificationService $verification */
        $verification = $container->get(MobileOtpVerificationService::class);
        try {
            $verification->verify($result->userId, null, $issued['otp'], '203.0.113.62');
        } catch (\Throwable) {
            // Result does not matter for this assertion; only the stored bucket keys do.
        }

        $pdo = DatabaseTestCase::pdo();
        $buckets = $pdo->query('SELECT bucket_key FROM rate_limit_buckets')->fetchAll();
        $joined = json_encode($buckets, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(strtolower($email), $joined);
        self::assertStringNotContainsString($mobile, $joined);
        self::assertStringNotContainsString('+91' . $mobile, $joined);
    }

    public function testStagingConfigurationRejectsRecordingSmsAdapter(): void
    {
        $builder = require dirname(__DIR__, 2) . '/config/security.php';
        $string = static function (string $key, string $default = '') {
            $map = [
                'RATE_LIMIT_PEPPER' => 'staging-rate-pepper',
                'TOKEN_PEPPER' => 'staging-token-pepper',
                'OTP_PEPPER' => 'staging-otp-pepper-different',
                'NOTIFICATION_DELIVERY_KEY' => base64_encode(str_repeat("\5", 32)),
                'NOTIFICATION_EMAIL_ADAPTER' => 'ses',
                'NOTIFICATION_SMS_ADAPTER' => 'recording',
                'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
                'TERMS_VERSION' => '2026-07-22',
                'PRIVACY_VERSION' => '2026-07-22',
            ];

            return $map[$key] ?? $default;
        };
        $bool = static fn (string $key, bool $default): bool => $default;
        $int = static function (string $key, int $default) use ($string): int {
            $value = $string($key, '');

            return $value === '' ? $default : (int) $value;
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recording SMS adapters are forbidden');
        $builder('staging', $bool, $string, $int);
    }
}
