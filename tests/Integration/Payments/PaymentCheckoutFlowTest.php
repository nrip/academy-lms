<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Payments;

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PaymentCheckoutFlowTest extends TestCase
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

    public function testInitiateCreatesPendingWithProviderOrder(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        /** @var PaymentCheckoutService $checkout */
        $checkout = $container->get(PaymentCheckoutService::class);

        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);

        self::assertSame(PaymentStatus::PENDING, $payment->status);
        self::assertNotNull($payment->providerOrderId);
        self::assertStringStartsWith('order_fake_', $payment->providerOrderId);
        self::assertSame(1, $payment->attemptNumber);
        self::assertSame(1180000, $payment->amountMinor);
        self::assertSame(1000000, $payment->baseFeeMinor);
        self::assertSame(180000, $payment->gstMinor);
        self::assertSame('INR', $payment->currency);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::PAYMENT_PENDING, $stmt->fetchColumn());

        self::assertFalse($this->tableExists($pdo, 'enrolments'));
    }

    public function testSecondInitiateWhilePendingConflicts(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $this->expectException(ConflictException::class);
        $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
    }

    public function testRetryWorksAfterFailedViaStateMachine(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        /** @var PaymentRepository $payments */
        $payments = $container->get(PaymentRepository::class);
        $stateMachine = $container->get(PaymentStateMachine::class);

        $first = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stateMachine->transition(
            PaymentStatus::PENDING,
            PaymentStatus::FAILED,
            ['system'],
            $now,
            'test_mark_failed',
        );
        self::assertTrue($payments->applyTransition(
            $first->paymentId,
            PaymentStatus::PENDING,
            PaymentStatus::FAILED,
            $first->rowVersion,
            $now,
            'test_failed',
            'gateway_error',
        ));

        $second = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        self::assertSame(PaymentStatus::PENDING, $second->status);
        self::assertSame(2, $second->attemptNumber);
        self::assertNotSame($first->paymentId, $second->paymentId);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::PAYMENT_PENDING, $stmt->fetchColumn());
    }

    public function testAmountSnapshotMatchesCatalogueAndHistoryIsAppendOnly(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication([
            'course_overrides' => ['standard_fee' => '5000.00', 'gst_rate' => '18.00'],
        ]);
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);

        self::assertSame(500000, $payment->baseFeeMinor);
        self::assertSame(90000, $payment->gstMinor);
        self::assertSame(590000, $payment->amountMinor);
        self::assertSame(
            PaymentAmountSnapshot::decimalToMinor('5000.00')
                + PaymentAmountSnapshot::decimalToMinor('900.00'),
            $payment->amountMinor,
        );

        /** @var PaymentStatusHistoryRepository $history */
        $history = $container->get(PaymentStatusHistoryRepository::class);
        $rows = $history->listByPaymentId($payment->paymentId);
        self::assertGreaterThanOrEqual(2, count($rows));

        $pdo = DatabaseTestCase::pdo();
        $historyId = $rows[0]['history_id'];
        $this->expectException(PDOException::class);
        $pdo->prepare('UPDATE payment_status_history SET reason = ? WHERE history_id = ?')
            ->execute(['tampered', $historyId]);
    }

    public function testSuccessfulMarkerUniqueness(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        /** @var PaymentRepository $payments */
        $payments = $container->get(PaymentRepository::class);
        $stateMachine = $container->get(PaymentStateMachine::class);

        $first = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stateMachine->transition(
            PaymentStatus::PENDING,
            PaymentStatus::SUCCESSFUL,
            ['system'],
            $now,
        );
        self::assertTrue($payments->applyTransition(
            $first->paymentId,
            PaymentStatus::PENDING,
            PaymentStatus::SUCCESSFUL,
            $first->rowVersion,
            $now,
            successfulMarker: 1,
        ));

        $pdo = DatabaseTestCase::pdo();
        $nowStr = $now->format('Y-m-d H:i:s.u');

        $this->expectException(PDOException::class);
        $pdo->prepare(
            'INSERT INTO payments (
                public_reference, application_id, user_id, provider, provider_order_id, provider_payment_id,
                base_fee_minor, gst_minor, amount_minor, currency, gst_rate_percent,
                course_version_id, batch_id, fee_override_applied, status, failure_code, failure_category,
                attempt_number, idempotency_key, row_version, successful_marker, initiated_at,
                provider_order_bound_at, authorized_at, captured_at, failed_at, expired_at, reconciled_at,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, \'razorpay\', \'order_dup\', NULL,
                1000000, 180000, 1180000, \'INR\', 18.00,
                ?, ?, NULL, \'successful\', NULL, NULL,
                2, ?, 1, 1, ?,
                ?, NULL, ?, NULL, NULL, NULL,
                ?, ?
            )',
        )->execute([
            'PAY-DUP-MARKER',
            $fixture['application_id'],
            $fixture['applicant_user_id'],
            $fixture['course_version_id'],
            $fixture['batch_id'],
            'pay:dup:marker',
            $nowStr,
            $nowStr,
            $nowStr,
            $nowStr,
            $nowStr,
        ]);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
