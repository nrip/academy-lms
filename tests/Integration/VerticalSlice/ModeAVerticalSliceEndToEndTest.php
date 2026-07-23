<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\VerticalSlice;

use Academy\Application\Dashboard\LearnerDashboardQueryService;
use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Application\Payments\PaymentWebhookProcessor;
use Academy\Application\Payments\RazorpayWebhookIngressService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;

/**
 * Mode A vertical slice: payment_pending → checkout → webhook admit → notifications → dashboard.
 */
final class ModeAVerticalSliceEndToEndTest extends TestCase
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

    protected function tearDown(): void
    {
        putenv('PAYMENTS_FAKE_GATEWAY');
        unset($_ENV['PAYMENTS_FAKE_GATEWAY'], $_SERVER['PAYMENTS_FAKE_GATEWAY']);
        parent::tearDown();
    }

    public function testModeAPaymentToDashboardAndNotifications(): void
    {
        $owner = PaymentTestFixture::seedPaymentPendingApplication();
        $intruder = PaymentTestFixture::seedPaymentPendingApplication();

        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($owner['applicant_auth'], $owner['application_id']);
        self::assertSame(PaymentStatus::PENDING, $payment->status);
        self::assertNotNull($payment->providerOrderId);

        /** @var FakePaymentGateway $gateway */
        $gateway = $container->get(\Academy\Domain\Payments\PaymentGateway::class);
        $captured = $gateway->simulateCapture(
            $payment->providerOrderId,
            $payment->amountMinor,
            $payment->currency,
            'pay_mode_a_e2e',
        );

        $payload = [
            'id' => 'evt_mode_a_e2e',
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

        $ingress = $container->get(RazorpayWebhookIngressService::class);
        self::assertFalse($ingress->receive($raw, $signature, 'application/json')['duplicate']);
        self::assertTrue($ingress->receive($raw, $signature, 'application/json')['duplicate']);

        $processed = $container->get(PaymentWebhookProcessor::class)->run('mode-a-e2e');
        self::assertGreaterThanOrEqual(1, $processed);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status, successful_marker FROM payments WHERE payment_id = ?');
        $stmt->execute([$payment->paymentId]);
        $payRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('successful', $payRow['status']);
        self::assertSame(1, (int) $payRow['successful_marker']);

        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$owner['application_id']]);
        self::assertSame(ApplicationStatus::ADMITTED, $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*), MIN(lifecycle_status) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$owner['application_id']]);
        $enrolment = $stmt->fetch(\PDO::FETCH_NUM);
        self::assertSame(1, (int) $enrolment[0]);
        self::assertContains((string) $enrolment[1], [
            EnrolmentLifecycleStatus::SCHEDULED,
            EnrolmentLifecycleStatus::ACTIVE,
        ]);

        $container->get(TransactionalNotificationDeliveryWorker::class)->run('mode-a-notif', 50);

        $eventTypes = $pdo->query(
            "SELECT DISTINCT source_event_type FROM notification_deliveries
             WHERE status = 'delivered'
               AND source_event_type IN (
                 'payment.successful', 'application.admitted', 'enrolment.created', 'application.approved'
               )",
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotEmpty($eventTypes);
        $intersect = array_intersect(
            $eventTypes,
            ['payment.successful', 'application.admitted', 'enrolment.created'],
        );
        self::assertNotEmpty($intersect, 'Expected at least one admit-path delivery');

        /** @var LearnerDashboardQueryService $dashboard */
        $dashboard = $container->get(LearnerDashboardQueryService::class);
        $ownerView = $dashboard->getDashboard($owner['applicant_auth']);
        $ownerCard = null;
        foreach ($ownerView->cards as $card) {
            if ($card->applicationId === $owner['application_id']) {
                $ownerCard = $card;
                break;
            }
        }
        self::assertNotNull($ownerCard);
        self::assertNotNull($ownerCard->enrolmentId);
        self::assertContains($ownerCard->enrolmentPresentation?->label, ['Scheduled', 'Active']);

        $stmt->execute([$owner['application_id']]);
        self::assertSame(1, (int) $stmt->fetch(\PDO::FETCH_NUM)[0], 'No duplicate enrolment');

        $intruderView = $dashboard->getDashboard($intruder['applicant_auth']);
        $intruderIds = array_map(static fn ($c): int => $c->applicationId, $intruderView->cards);
        self::assertNotContains($owner['application_id'], $intruderIds);
        self::assertContains($intruder['application_id'], $intruderIds);
    }
}
