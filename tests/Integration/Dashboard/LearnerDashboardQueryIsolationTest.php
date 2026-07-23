<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Dashboard;

use Academy\Application\Dashboard\LearnerDashboardQueryService;
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

final class LearnerDashboardQueryIsolationTest extends TestCase
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

    public function testEachLearnerSeesOnlyOwnApplications(): void
    {
        $owner = PaymentTestFixture::seedPaymentPendingApplication();
        $other = PaymentTestFixture::seedPaymentPendingApplication();

        $container = ApplicationFactory::container('testing');
        /** @var LearnerDashboardQueryService $dashboard */
        $dashboard = $container->get(LearnerDashboardQueryService::class);

        $ownerView = $dashboard->getDashboard($owner['applicant_auth']);
        $otherView = $dashboard->getDashboard($other['applicant_auth']);

        $ownerIds = array_map(static fn ($c): int => $c->applicationId, $ownerView->cards);
        $otherIds = array_map(static fn ($c): int => $c->applicationId, $otherView->cards);

        self::assertContains($owner['application_id'], $ownerIds);
        self::assertNotContains($other['application_id'], $ownerIds);
        self::assertContains($other['application_id'], $otherIds);
        self::assertNotContains($owner['application_id'], $otherIds);
    }

    public function testAdmittedEnrolmentAppearsWithoutContentProgress(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        self::assertSame(PaymentStatus::PENDING, $payment->status);

        /** @var FakePaymentGateway $gateway */
        $gateway = $container->get(\Academy\Domain\Payments\PaymentGateway::class);
        $captured = $gateway->simulateCapture(
            $payment->providerOrderId,
            $payment->amountMinor,
            $payment->currency,
            'pay_dash_iso_1',
        );

        $payload = [
            'id' => 'evt_dash_iso_1',
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
        $container->get(RazorpayWebhookIngressService::class)->receive($raw, $signature, 'application/json');
        $container->get(PaymentWebhookProcessor::class)->run('dash-iso-worker');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::ADMITTED, $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT lifecycle_status FROM enrolments WHERE application_id = ? AND user_id = ?',
        );
        $stmt->execute([$fixture['application_id'], $fixture['applicant_user_id']]);
        $lifecycle = (string) $stmt->fetchColumn();
        self::assertContains($lifecycle, [
            EnrolmentLifecycleStatus::SCHEDULED,
            EnrolmentLifecycleStatus::ACTIVE,
        ]);

        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'content_progress'",
        );
        if ((int) $exists->fetchColumn() === 1) {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM content_progress')->fetchColumn());
        }

        /** @var LearnerDashboardQueryService $dashboard */
        $dashboard = $container->get(LearnerDashboardQueryService::class);
        $view = $dashboard->getDashboard($fixture['applicant_auth']);
        $card = null;
        foreach ($view->cards as $candidate) {
            if ($candidate->applicationId === $fixture['application_id']) {
                $card = $candidate;
                break;
            }
        }
        self::assertNotNull($card);
        self::assertNotNull($card->enrolmentId);
        self::assertNotNull($card->enrolmentPresentation);
        self::assertContains($card->enrolmentPresentation->label, ['Scheduled', 'Active']);
    }
}
