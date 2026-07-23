<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use InvalidArgumentException;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class Wp07NotificationSecurityTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;

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

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testStagingForbidsRecordingEmailAdapter(): void
    {
        $builder = require dirname(__DIR__, 2) . '/config/security.php';
        $string = static function (string $key, string $default = '') {
            $map = [
                'RATE_LIMIT_PEPPER' => 'staging-rate-pepper',
                'TOKEN_PEPPER' => 'staging-token-pepper',
                'OTP_PEPPER' => 'staging-otp-pepper-different',
                'NOTIFICATION_DELIVERY_KEY' => base64_encode(str_repeat("\5", 32)),
                'NOTIFICATION_EMAIL_ADAPTER' => 'recording',
                'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
                'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
            ];

            return $map[$key] ?? $default;
        };
        $bool = static fn (string $key, bool $default): bool => $default;
        $int = static function (string $key, int $default) use ($string): int {
            $value = $string($key, '');

            return $value === '' ? $default : (int) $value;
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recording/local email adapters are forbidden');
        $builder('staging', $bool, $string, $int);
    }

    public function testAdminNotificationHtmlDoesNotLeakRecipientEmailOrBody(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $emailStmt = DatabaseTestCase::pdo()->prepare('SELECT email FROM users WHERE user_id = ?');
        $emailStmt->execute([$fixture['applicant_user_id']]);
        $rawEmail = (string) $emailStmt->fetchColumn();

        $container = ApplicationFactory::container('testing');
        $container->get(TransactionalNotificationDeliveryWorker::class)->run('sec-notif', 20);

        $deliveryId = (int) DatabaseTestCase::pdo()->query(
            "SELECT notification_delivery_id FROM notification_deliveries
             WHERE source_event_type = 'application.approved' LIMIT 1",
        )->fetchColumn();
        self::assertGreaterThan(0, $deliveryId);

        $admin = DatabaseTestCase::superAdminFixture();
        $session = DatabaseTestCase::bindSessionForUser(
            $admin['user_id'],
            $admin['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/notifications/' . $deliveryId, 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $session['session'],
                    $this->csrfCookieName => $session['csrf'],
                ]),
        );
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        self::assertStringNotContainsString($rawEmail, $body);
        self::assertStringNotContainsString('delivery_ciphertext', $body);
        self::assertStringNotContainsString('Body ', $body);
        self::assertStringContainsString('@', $body); // masked recipient still has domain
    }

    public function testFinanceStillCannotDownloadDocuments(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/applications/' . $fixture['application_id']
                    . '/documents/' . $fixture['submission_ids'][0] . '/download',
                'GET',
            ))
                ->withCookieParams([
                    $this->sessionCookieName => $fixture['finance_session']['session'],
                    $this->csrfCookieName => $fixture['finance_session']['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDemoFixtureRefusesStaging(): void
    {
        putenv('APP_ENV=staging');
        $_ENV['APP_ENV'] = 'staging';
        $_SERVER['APP_ENV'] = 'staging';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must never run in staging/production');
            \Academy\Tests\Support\Wp07DemoFixture::seedPaymentPending();
        } finally {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = 'testing';
            $_SERVER['APP_ENV'] = 'testing';
        }
    }
}
