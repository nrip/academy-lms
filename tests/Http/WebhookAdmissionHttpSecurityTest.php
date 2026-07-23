<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use InvalidArgumentException;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\TestCase;

final class WebhookAdmissionHttpSecurityTest extends TestCase
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
        putenv('PAYMENTS_FAKE_GATEWAY=1');
        $_ENV['PAYMENTS_FAKE_GATEWAY'] = '1';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    protected function tearDown(): void
    {
        putenv('PAYMENTS_FAKE_GATEWAY');
        unset($_ENV['PAYMENTS_FAKE_GATEWAY'], $_SERVER['PAYMENTS_FAKE_GATEWAY']);
        putenv('RAZORPAY_WEBHOOK_SECRET');
        unset($_ENV['RAZORPAY_WEBHOOK_SECRET'], $_SERVER['RAZORPAY_WEBHOOK_SECRET']);
        parent::tearDown();
    }

    public function testMalformedJsonWebhookReturns422AndNoReceipt(): void
    {
        $raw = '{"id":"evt_bad",';
        $signature = hash_hmac(
            'sha256',
            $raw,
            'local-ci-razorpay-webhook-secret-not-for-production',
        );

        $response = ApplicationFactory::handle($this->webhookRequest($raw, $signature));
        self::assertSame(422, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());
    }

    public function testOversizedWebhookBodyRejected(): void
    {
        $raw = '{"id":"' . str_repeat('x', 1_048_600) . '"}';
        $signature = hash_hmac(
            'sha256',
            $raw,
            'local-ci-razorpay-webhook-secret-not-for-production',
        );

        $response = ApplicationFactory::handle($this->webhookRequest($raw, $signature));
        self::assertSame(422, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());
    }

    public function testInvalidSignatureProducesNoTrustedReceipt(): void
    {
        $raw = '{"id":"evt_invalid_sig","event":"payment.captured","payload":{}}';
        $response = ApplicationFactory::handle($this->webhookRequest($raw, 'definitely-not-valid'));
        self::assertSame(401, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());

        $audit = $pdo->query(
            "SELECT action, new_value FROM audit_log WHERE action = 'payment.webhook_rejected' ORDER BY audit_id DESC LIMIT 1",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($audit);
        self::assertStringNotContainsString('definitely-not-valid', (string) $audit['new_value']);
        self::assertStringNotContainsString($raw, (string) $audit['new_value']);
    }

    public function testDuplicateValidWebhookRemainsIdempotent(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $payment = $container->get(PaymentCheckoutService::class)
            ->initiate($fixture['applicant_auth'], $fixture['application_id']);

        /** @var FakePaymentGateway $gateway */
        $gateway = $container->get(\Academy\Domain\Payments\PaymentGateway::class);
        $captured = $gateway->simulateCapture(
            (string) $payment->providerOrderId,
            $payment->amountMinor,
            $payment->currency,
            'pay_http_dup_' . $payment->paymentId,
        );

        $payload = [
            'id' => 'evt_http_dup_' . $payment->paymentId,
            'event' => 'payment.captured',
            'created_at' => time(),
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $captured->providerPaymentId,
                        'order_id' => $payment->providerOrderId,
                        'amount' => $payment->amountMinor,
                        'currency' => $payment->currency,
                        'status' => 'captured',
                        'captured' => true,
                    ],
                ],
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $container->get(FakeWebhookSigner::class)->sign($raw);

        $first = ApplicationFactory::handle($this->webhookRequest($raw, $signature));
        $second = ApplicationFactory::handle($this->webhookRequest($raw, $signature));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        $firstBody = json_decode((string) $first->getBody(), true);
        $secondBody = json_decode((string) $second->getBody(), true);
        self::assertFalse($firstBody['duplicate']);
        self::assertTrue($secondBody['duplicate']);

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());
    }

    public function testFinanceReconcileRetryRequiresCsrf(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $payment = $container->get(PaymentCheckoutService::class)
            ->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/finance/payments/' . $payment->paymentId . '/reconcile',
                'POST',
            ))
                ->withParsedBody(['reason' => 'manual retry'])
                ->withCookieParams([
                    $this->sessionCookieName => $fixture['finance_session']['session'],
                    $this->csrfCookieName => $fixture['finance_session']['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFinanceReconcileRetryRequiresPermission(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $payment = $container->get(PaymentCheckoutService::class)
            ->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/finance/payments/' . $payment->paymentId . '/reconcile',
                'POST',
            ))
                ->withParsedBody(['reason' => 'manual retry'])
                ->withHeader('X-CSRF-Token', $fixture['applicant_session']['csrf'])
                ->withCookieParams([
                    $this->sessionCookieName => $fixture['applicant_session']['session'],
                    $this->csrfCookieName => $fixture['applicant_session']['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPendingFinanceUserDeniedReconcileRetry(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $payment = $container->get(PaymentCheckoutService::class)
            ->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $pending = DatabaseTestCase::createSyntheticUser(
            'finance.pending.' . bin2hex(random_bytes(3)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::FINANCE_ADMIN],
            AccountStatus::PENDING_VERIFICATION,
        );
        $session = DatabaseTestCase::bindSessionForUser(
            $pending['user_id'],
            $pending['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/finance/payments/' . $payment->paymentId . '/reconcile',
                'POST',
            ))
                ->withParsedBody(['reason' => 'manual retry'])
                ->withHeader('X-CSRF-Token', $session['csrf'])
                ->withCookieParams([
                    $this->sessionCookieName => $session['session'],
                    $this->csrfCookieName => $session['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSuspendedFinanceUserDeniedReconcileRetry(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $payment = $container->get(PaymentCheckoutService::class)
            ->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $suspended = DatabaseTestCase::createSyntheticUser(
            'finance.susp.' . bin2hex(random_bytes(3)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::FINANCE_ADMIN],
            AccountStatus::SUSPENDED,
        );
        $session = DatabaseTestCase::bindSessionForUser(
            $suspended['user_id'],
            $suspended['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/finance/payments/' . $payment->paymentId . '/reconcile',
                'POST',
            ))
                ->withParsedBody(['reason' => 'manual retry'])
                ->withHeader('X-CSRF-Token', $session['csrf'])
                ->withCookieParams([
                    $this->sessionCookieName => $session['session'],
                    $this->csrfCookieName => $session['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFakeWebhookSignerDeniedOutsideLocalTestingCi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakeWebhookSigner('staging', true, 'secret');
    }

    public function testFakePaymentGatewayDeniedInProductionLikeEnv(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FakePaymentGateway('production', true);
    }

    public function testWebhookSecretsDoNotAppearInAuditOrFinanceHtml(): void
    {
        putenv('RAZORPAY_WEBHOOK_SECRET=super-secret-webhook-value-do-not-leak');
        $_ENV['RAZORPAY_WEBHOOK_SECRET'] = 'super-secret-webhook-value-do-not-leak';
        $_SERVER['RAZORPAY_WEBHOOK_SECRET'] = 'super-secret-webhook-value-do-not-leak';

        try {
            $fixture = PaymentTestFixture::seedPaymentPendingApplication();
            $container = ApplicationFactory::container('testing');
            $payment = $container->get(PaymentCheckoutService::class)
                ->initiate($fixture['applicant_auth'], $fixture['application_id']);

            $raw = '{"id":"evt_secret_probe","event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_x","order_id":"ord_x","amount":1,"currency":"INR","status":"captured","captured":true}}}}';
            $badSignature = 'bad-signature-super-secret-webhook-value-do-not-leak';
            ApplicationFactory::handle($this->webhookRequest($raw, $badSignature));

            $pdo = DatabaseTestCase::pdo();
            $audits = $pdo->query('SELECT action, previous_value, new_value, reason FROM audit_log')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($audits as $audit) {
                $blob = json_encode($audit, JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('super-secret-webhook-value-do-not-leak', $blob);
                self::assertStringNotContainsString($raw, $blob);
                self::assertStringNotContainsString($badSignature, $blob);
            }

            $html = ApplicationFactory::handle(
                (new ServerRequest(
                    [],
                    [],
                    'http://localhost/finance/payments/' . $payment->paymentId,
                    'GET',
                ))
                    ->withCookieParams([
                        $this->sessionCookieName => $fixture['finance_session']['session'],
                        $this->csrfCookieName => $fixture['finance_session']['csrf'],
                    ]),
            );
            $body = (string) $html->getBody();
            self::assertSame(200, $html->getStatusCode());
            self::assertStringNotContainsString('super-secret-webhook-value-do-not-leak', $body);
            self::assertStringNotContainsString('RAZORPAY_WEBHOOK_SECRET', $body);
            self::assertStringNotContainsString($badSignature, $body);
        } finally {
            putenv('RAZORPAY_WEBHOOK_SECRET');
            unset($_ENV['RAZORPAY_WEBHOOK_SECRET'], $_SERVER['RAZORPAY_WEBHOOK_SECRET']);
        }
    }

    private function webhookRequest(string $rawBody, string $signature): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($rawBody);
        $stream->rewind();

        return (new ServerRequest(
            [],
            [],
            'http://localhost/webhooks/razorpay',
            'POST',
        ))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Razorpay-Signature', $signature)
            ->withBody($stream);
    }
}
