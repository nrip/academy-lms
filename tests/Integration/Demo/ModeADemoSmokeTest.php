<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Demo;

use Academy\Application\Ops\DemoPrepareService;
use Academy\Application\Ops\DemoProcessService;
use Academy\Application\Payments\DemoPaymentSimulationService;
use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;

/**
 * Demo smoke: prepare idempotency + fake capture webhook → process → admit/enrol.
 */
final class ModeADemoSmokeTest extends TestCase
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
        putenv('DOCUMENTS_FAKE_SCANNER=1');
        $_ENV['DOCUMENTS_FAKE_SCANNER'] = '1';
        putenv('DOCUMENTS_STORAGE_DRIVER=local');
        $_ENV['DOCUMENTS_STORAGE_DRIVER'] = 'local';
        putenv('NOTIFICATION_EMAIL_ADAPTER=recording');
        $_ENV['NOTIFICATION_EMAIL_ADAPTER'] = 'recording';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    protected function tearDown(): void
    {
        putenv('PAYMENTS_FAKE_GATEWAY');
        putenv('DOCUMENTS_FAKE_SCANNER');
        putenv('DOCUMENTS_STORAGE_DRIVER');
        putenv('NOTIFICATION_EMAIL_ADAPTER');
        unset(
            $_ENV['PAYMENTS_FAKE_GATEWAY'],
            $_ENV['DOCUMENTS_FAKE_SCANNER'],
            $_ENV['DOCUMENTS_STORAGE_DRIVER'],
            $_ENV['NOTIFICATION_EMAIL_ADAPTER'],
            $_SERVER['PAYMENTS_FAKE_GATEWAY'],
        );
        parent::tearDown();
    }

    public function testDemoPrepareIsIdempotentAndPaymentPathAdmits(): void
    {
        $container = ApplicationFactory::container('testing');
        $prepare = $container->get(DemoPrepareService::class);

        $first = $prepare->prepare(true, false);
        $second = $prepare->prepare(true, false);

        self::assertTrue($first['catalogue']);
        self::assertSame($first['personas'], $second['personas']);
        self::assertGreaterThanOrEqual(5, $first['personas']);
        self::assertGreaterThanOrEqual(8, $first['applications']);
        self::assertSame('learner@uat.example.test', $first['credentials'][0]['email']);

        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        self::assertSame(PaymentStatus::PENDING, $payment->status);

        $gateway = $container->get(\Academy\Domain\Payments\PaymentGateway::class);
        self::assertInstanceOf(FakePaymentGateway::class, $gateway);

        $demoPay = $container->get(DemoPaymentSimulationService::class);
        self::assertTrue($demoPay->isAvailable());
        $ingress = $demoPay->simulateBrowserCapture(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $payment->paymentId,
        );
        self::assertTrue($ingress['confirming']);
        self::assertFalse($ingress['duplicate']);
        self::assertGreaterThan(0, $ingress['processed']);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM payments WHERE payment_id = ?');
        $stmt->execute([$payment->paymentId]);
        self::assertSame(
            PaymentStatus::SUCCESSFUL,
            $stmt->fetchColumn(),
            'Fake-gateway demo capture processes the webhook in-process (still not browser-trusted)',
        );

        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::ADMITTED, $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        // Rerun process is safe.
        $container->get(DemoProcessService::class)->process('demo-smoke-2', 25);
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
