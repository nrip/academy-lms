<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Payments;

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Application\Payments\PaymentWebhookProcessor;
use Academy\Application\Payments\RazorpayWebhookIngressService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;

final class WebhookAdmissionEnrolmentFlowTest extends TestCase
{
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
    }

    public function testCapturedWebhookAdmitsAndCreatesEnrolment(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        self::assertSame(PaymentStatus::PENDING, $payment->status);
        self::assertNotNull($payment->providerOrderId);

        /** @var FakePaymentGateway $gateway */
        $gateway = $container->get(\Academy\Domain\Payments\PaymentGateway::class);
        $captured = $gateway->simulateCapture(
            $payment->providerOrderId,
            $payment->amountMinor,
            $payment->currency,
            'pay_flow_1',
        );

        $payload = [
            'id' => 'evt_flow_1',
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
        $signer = $container->get(FakeWebhookSigner::class);
        $signature = $signer->sign($raw);

        $ingress = $container->get(RazorpayWebhookIngressService::class);
        $first = $ingress->receive($raw, $signature, 'application/json');
        self::assertFalse($first['duplicate']);
        $second = $ingress->receive($raw, $signature, 'application/json');
        self::assertTrue($second['duplicate']);

        $processed = $container->get(PaymentWebhookProcessor::class)->run('test-worker');
        self::assertGreaterThanOrEqual(1, $processed);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status, successful_marker FROM payments WHERE payment_id = ?');
        $stmt->execute([$payment->paymentId]);
        $payRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('successful', $payRow['status']);
        self::assertSame(1, (int) $payRow['successful_marker']);

        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::ADMITTED, $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        // Duplicate process is harmless.
        $container->get(PaymentWebhookProcessor::class)->run('test-worker-2');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testInvalidSignatureDoesNotPersistReceipt(): void
    {
        $container = ApplicationFactory::container('testing');
        $ingress = $container->get(RazorpayWebhookIngressService::class);
        $raw = '{"id":"evt_bad","event":"payment.captured","payload":{}}';

        try {
            $ingress->receive($raw, 'not-a-valid-signature', 'application/json');
            self::fail('Expected authentication failure');
        } catch (\Academy\Domain\Exception\AuthenticationException) {
            self::assertTrue(true);
        }

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());
    }
}
