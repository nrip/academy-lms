<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use InvalidArgumentException;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PaymentCheckoutSecurityTest extends TestCase
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

    public function testClientCannotSetAmountOnInitiate(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/applications/' . $fixture['application_id'] . '/payments',
                'POST',
            ))
                ->withParsedBody([
                    'amount_minor' => 1,
                    'amount' => '1.00',
                    'currency' => 'USD',
                ])
                ->withHeader('Accept', 'application/json')
                ->withHeader('X-CSRF-Token', $fixture['applicant_session']['csrf'])
                ->withCookieParams([
                    $this->sessionCookieName => $fixture['applicant_session']['session'],
                    $this->csrfCookieName => $fixture['applicant_session']['csrf'],
                ]),
        );

        self::assertSame(201, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame(1180000, $payload['amount_minor']);
        self::assertSame('INR', $payload['currency']);
    }

    public function testFakeGatewayDeniedInProductionConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakePaymentGateway('production', true);
    }

    public function testKeySecretNotExposedInPaymentHtml(): void
    {
        putenv('RAZORPAY_KEY_SECRET=super-secret-test-value-do-not-leak');
        $_ENV['RAZORPAY_KEY_SECRET'] = 'super-secret-test-value-do-not-leak';
        $_SERVER['RAZORPAY_KEY_SECRET'] = 'super-secret-test-value-do-not-leak';

        try {
            $fixture = PaymentTestFixture::seedPaymentPendingApplication();
            $container = ApplicationFactory::container('testing');
            $payment = $container->get(PaymentCheckoutService::class)
                ->initiate($fixture['applicant_auth'], $fixture['application_id']);

            $response = ApplicationFactory::handle(
                (new ServerRequest(
                    [],
                    [],
                    'http://localhost/applications/' . $fixture['application_id']
                        . '/payments/' . $payment->paymentId,
                    'GET',
                ))
                    ->withCookieParams([
                        $this->sessionCookieName => $fixture['applicant_session']['session'],
                        $this->csrfCookieName => $fixture['applicant_session']['csrf'],
                    ]),
            );

            $body = (string) $response->getBody();
            self::assertSame(200, $response->getStatusCode());
            self::assertStringNotContainsString('super-secret-test-value-do-not-leak', $body);
            self::assertStringNotContainsString('RAZORPAY_KEY_SECRET', $body);
        } finally {
            putenv('RAZORPAY_KEY_SECRET');
            unset($_ENV['RAZORPAY_KEY_SECRET'], $_SERVER['RAZORPAY_KEY_SECRET']);
        }
    }

    public function testCheckoutReturnDoesNotMarkPaymentSuccessful(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $returned = $checkout->recordCheckoutReturn(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $payment->paymentId,
        );

        self::assertSame(PaymentStatus::PENDING, $returned->status);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM payments WHERE payment_id = ?');
        $stmt->execute([$payment->paymentId]);
        self::assertSame(PaymentStatus::PENDING, $stmt->fetchColumn());
    }

    public function testFinanceCannotAccessDocumentDownload(): void
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

    public function testStagingSecurityConfigRejectsFakeGateway(): void
    {
        $bool = static function (string $key, bool $default): bool {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value === false || $value === null || $value === '') {
                return $default;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        };
        $string = static function (string $key, string $default): string {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value === false || $value === null || $value === '') {
                return $default;
            }

            return (string) $value;
        };
        $int = static function (string $key, int $default): int {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value === false || $value === null || $value === '') {
                return $default;
            }

            return (int) $value;
        };

        $_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
        $_ENV['RAZORPAY_KEY_ID'] = 'rzp_live_test';
        $_ENV['RAZORPAY_KEY_SECRET'] = 'secret';
        $_ENV['TOKEN_PEPPER'] = 'staging-token-pepper-value';
        $_ENV['OTP_PEPPER'] = 'staging-otp-pepper-different';
        $_ENV['NOTIFICATION_DELIVERY_KEY'] = base64_encode(str_repeat('a', 32));
        $_ENV['RATE_LIMIT_PEPPER'] = 'staging-rate-limit-pepper';

        /** @var callable(string, callable, callable, callable): array $builder */
        $builder = require dirname(__DIR__, 2) . '/config/security.php';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYMENTS_FAKE_GATEWAY is forbidden');
        try {
            $builder('staging', $bool, $string, $int);
        } finally {
            unset(
                $_ENV['PAYMENTS_FAKE_GATEWAY'],
                $_ENV['RAZORPAY_KEY_ID'],
                $_ENV['RAZORPAY_KEY_SECRET'],
                $_ENV['TOKEN_PEPPER'],
                $_ENV['OTP_PEPPER'],
                $_ENV['NOTIFICATION_DELIVERY_KEY'],
                $_ENV['RATE_LIMIT_PEPPER'],
            );
        }
    }
}
