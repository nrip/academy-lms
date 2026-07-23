<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Payments;

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Learning\EnrolmentOutboxEventTypes;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentOutboxEventTypes;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class WebhookAdmissionConcurrencyTest extends TestCase
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
        putenv('PAYMENTS_RECONCILE_PENDING_STALE_SECONDS=0');
        $_ENV['PAYMENTS_RECONCILE_PENDING_STALE_SECONDS'] = '0';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    protected function tearDown(): void
    {
        putenv('PAYMENTS_FAKE_GATEWAY');
        unset($_ENV['PAYMENTS_FAKE_GATEWAY'], $_SERVER['PAYMENTS_FAKE_GATEWAY']);
        putenv('PAYMENTS_RECONCILE_PENDING_STALE_SECONDS');
        unset(
            $_ENV['PAYMENTS_RECONCILE_PENDING_STALE_SECONDS'],
            $_SERVER['PAYMENTS_RECONCILE_PENDING_STALE_SECONDS'],
        );
        parent::tearDown();
    }

    public function testDuplicateWebhookWorkersProduceOneEnrolment(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $worker = dirname(__DIR__, 2) . '/Support/webhook_capture_worker.php';

        $bootstrap = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/webhook_capture_prepare.php',
            (string) $fixture['applicant_user_id'],
            (string) $fixture['applicant_auth_version'],
            (string) $fixture['application_id'],
        ];
        $prepared = $this->runWorkers([$bootstrap]);
        self::assertCount(1, $prepared);
        self::assertTrue(str_starts_with($prepared[0], 'ready:'), $prepared[0]);
        $paymentId = (int) substr($prepared[0], strlen('ready:'));

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, (string) $paymentId, 'evt_dup_1'],
            [PHP_BINARY, $worker, (string) $paymentId, 'evt_dup_1'],
        ]);

        self::assertSame(2, count($results), implode(',', $results));
        foreach ($results as $result) {
            self::assertTrue(
                str_starts_with($result, 'ok:') || $result === 'duplicate_or_noop',
                'Unexpected: ' . implode(',', $results),
            );
        }

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM payments WHERE application_id = ? AND successful_marker = 1',
        );
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testTwoSuccessfulPaymentsRacingSameApplication(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $first = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);
        $secondId = $this->insertSiblingPendingPayment($first->paymentId);

        $accept = dirname(__DIR__, 2) . '/Support/payment_accept_worker.php';
        $results = $this->runWorkers([
            [PHP_BINARY, $accept, (string) $first->paymentId, 'pay_race_a_' . $first->paymentId],
            [PHP_BINARY, $accept, (string) $secondId, 'pay_race_b_' . $secondId],
        ]);

        self::assertCount(2, $results, implode(',', $results));
        $outcomes = [];
        foreach ($results as $result) {
            self::assertTrue(str_starts_with($result, 'ok:'), 'Unexpected: ' . implode(',', $results));
            $outcomes[] = substr($result, 3);
        }
        self::assertContains('accepted', $outcomes);
        self::assertTrue(
            in_array('duplicate', $outcomes, true) || in_array('accepted', $outcomes, true),
        );

        $pdo = DatabaseTestCase::pdo();
        $appId = $fixture['application_id'];

        $stmt = $pdo->prepare('SELECT status, successful_marker FROM payments WHERE application_id = ? ORDER BY payment_id');
        $stmt->execute([$appId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);

        $successful = 0;
        $markers = 0;
        $recon = 0;
        foreach ($rows as $row) {
            if ($row['status'] === PaymentStatus::SUCCESSFUL) {
                ++$successful;
            }
            if ((int) ($row['successful_marker'] ?? 0) === 1) {
                ++$markers;
            }
            if ($row['status'] === PaymentStatus::RECONCILIATION_PENDING) {
                ++$recon;
            }
        }
        self::assertSame(1, $successful);
        self::assertSame(1, $markers);
        self::assertSame(1, $recon);

        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$appId]);
        self::assertSame(ApplicationStatus::ADMITTED, $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$appId]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM enrolments WHERE batch_id = ? AND lifecycle_status IN (\'scheduled\', \'active\')',
        );
        $stmt->execute([$fixture['batch_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT event_type, COUNT(*) AS c FROM outbox_messages
             WHERE event_type IN (?, ?, ?)
             GROUP BY event_type',
        );
        $stmt->execute([
            PaymentOutboxEventTypes::SUCCESSFUL,
            PaymentOutboxEventTypes::APPLICATION_ADMITTED,
            EnrolmentOutboxEventTypes::CREATED,
        ]);
        $outbox = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $outbox[$row['event_type']] = (int) $row['c'];
        }
        self::assertSame(1, $outbox[PaymentOutboxEventTypes::SUCCESSFUL] ?? 0);
        self::assertSame(1, $outbox[PaymentOutboxEventTypes::APPLICATION_ADMITTED] ?? 0);
        self::assertSame(1, $outbox[EnrolmentOutboxEventTypes::CREATED] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM payment_status_history
             WHERE application_id = ? AND status_before = ? AND status_after = ?',
        );
        $stmt->execute([$appId, PaymentStatus::PENDING, PaymentStatus::SUCCESSFUL]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testTwoApplicationsRacingFinalBatchSeat(): void
    {
        $catalogue = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            batchOverrides: ['min_capacity' => 1, 'max_capacity' => 1],
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $first = PaymentTestFixture::seedPaymentPendingApplication(['catalogue' => $catalogue]);
        $second = PaymentTestFixture::seedPaymentPendingApplication(['catalogue' => $catalogue]);
        self::assertSame($first['batch_id'], $second['batch_id']);

        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payA = $checkout->initiate($first['applicant_auth'], $first['application_id']);
        $payB = $checkout->initiate($second['applicant_auth'], $second['application_id']);

        $accept = dirname(__DIR__, 2) . '/Support/payment_accept_worker.php';
        $results = $this->runWorkers([
            [PHP_BINARY, $accept, (string) $payA->paymentId, 'pay_seat_a_' . $payA->paymentId],
            [PHP_BINARY, $accept, (string) $payB->paymentId, 'pay_seat_b_' . $payB->paymentId],
        ]);
        self::assertCount(2, $results, implode(',', $results));
        foreach ($results as $result) {
            self::assertTrue(str_starts_with($result, 'ok:'), implode(',', $results));
        }
        $outcomes = array_map(static fn (string $r): string => substr($r, 3), $results);
        self::assertContains('accepted', $outcomes);
        self::assertContains('capacity_exhausted', $outcomes);

        $pdo = DatabaseTestCase::pdo();
        $batchId = $first['batch_id'];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE batch_id = ? AND status = ?');
        $stmt->execute([$batchId, ApplicationStatus::ADMITTED]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE batch_id = ?');
        $stmt->execute([$batchId]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM enrolments
                 WHERE batch_id = ? AND lifecycle_status IN (\'scheduled\', \'active\'))
                +
                (SELECT COUNT(*) FROM applications a
                 LEFT JOIN enrolments e ON e.application_id = a.application_id
                 WHERE a.batch_id = ? AND a.status = \'admitted\' AND e.enrolment_id IS NULL)
            )',
        );
        $stmt->execute([$batchId, $batchId]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT p.status AS payment_status, a.status AS application_status
             FROM payments p
             INNER JOIN applications a ON a.application_id = p.application_id
             WHERE p.payment_id IN (?, ?)',
        );
        $stmt->execute([$payA->paymentId, $payB->paymentId]);
        $pairs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $loserFound = false;
        $winnerFound = false;
        foreach ($pairs as $pair) {
            if ($pair['payment_status'] === PaymentStatus::SUCCESSFUL
                && $pair['application_status'] === ApplicationStatus::ADMITTED
            ) {
                $winnerFound = true;
            }
            if ($pair['payment_status'] === PaymentStatus::RECONCILIATION_PENDING
                && $pair['application_status'] === ApplicationStatus::PAYMENT_PENDING
            ) {
                $loserFound = true;
            }
        }
        self::assertTrue($winnerFound);
        self::assertTrue($loserFound);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM outbox_messages WHERE event_type = ?',
        );
        $stmt->execute([PaymentOutboxEventTypes::CAPACITY_EXHAUSTED_AFTER_PAYMENT]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->query('SELECT max_capacity FROM batches WHERE batch_id = ' . (int) $batchId);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testWebhookProcessingRacingWithReconciliation(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $bootstrap = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/webhook_capture_prepare.php',
            (string) $fixture['applicant_user_id'],
            (string) $fixture['applicant_auth_version'],
            (string) $fixture['application_id'],
        ];
        $prepared = $this->runWorkers([$bootstrap]);
        self::assertTrue(str_starts_with($prepared[0], 'ready:'), $prepared[0]);
        $paymentId = (int) substr($prepared[0], strlen('ready:'));

        $webhook = dirname(__DIR__, 2) . '/Support/webhook_capture_worker.php';
        $reconcile = dirname(__DIR__, 2) . '/Support/payment_reconcile_worker.php';
        $results = $this->runWorkers([
            [PHP_BINARY, $webhook, (string) $paymentId, 'evt_race_wh_' . $paymentId],
            [PHP_BINARY, $reconcile, (string) $paymentId],
        ]);
        self::assertCount(2, $results, implode(',', $results));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status, successful_marker FROM payments WHERE payment_id = ?');
        $stmt->execute([$paymentId]);
        $pay = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(PaymentStatus::SUCCESSFUL, $pay['status']);
        self::assertSame(1, (int) $pay['successful_marker']);

        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::ADMITTED, $stmt->fetchColumn());

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM payment_status_history
             WHERE payment_id = ? AND status_before = ? AND status_after = ?',
        );
        $stmt->execute([$paymentId, PaymentStatus::PENDING, PaymentStatus::SUCCESSFUL]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM outbox_messages WHERE event_type = ? AND aggregate_id = ?',
        );
        $stmt->execute([PaymentOutboxEventTypes::SUCCESSFUL, (string) $paymentId]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM audit_log WHERE action = ? AND affected_entity_id = ?',
        );
        $stmt->execute(['payment.success_accepted', (string) $paymentId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testStaleReconcileLeaseCannotOverwriteNewerPaymentState(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $checkout = $container->get(PaymentCheckoutService::class);
        $payment = $checkout->initiate($fixture['applicant_auth'], $fixture['application_id']);

        /** @var FakePaymentGateway $gateway */
        $gateway = $container->get(\Academy\Domain\Payments\PaymentGateway::class);
        $gateway->simulateCapture(
            (string) $payment->providerOrderId,
            $payment->amountMinor,
            $payment->currency,
            'pay_stale_main_' . $payment->paymentId,
        );

        $payments = $container->get(PaymentRepository::class);
        $claimed = $container->get(TransactionManager::class)->run(
            static fn (): array => $payments->claimForReconciliation(
                'stale-owner',
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                120,
                0,
                10,
            ),
        );
        $target = null;
        foreach ($claimed as $item) {
            if ($item->paymentId === $payment->paymentId) {
                $target = $item;
                break;
            }
        }
        self::assertNotNull($target);
        self::assertNotNull($target->reconcileLeaseToken);

        $accept = dirname(__DIR__, 2) . '/Support/payment_accept_worker.php';
        $stale = dirname(__DIR__, 2) . '/Support/payment_reconcile_stale_worker.php';

        $acceptResult = $this->runWorkers([
            [PHP_BINARY, $accept, (string) $payment->paymentId, 'pay_before_stale_' . $payment->paymentId],
        ]);
        self::assertTrue(str_starts_with($acceptResult[0], 'ok:accepted'), $acceptResult[0]);

        $staleResults = $this->runWorkers([
            [
                PHP_BINARY,
                $stale,
                (string) $payment->paymentId,
                'stale-owner',
                (string) $target->reconcileLeaseToken,
                (string) $target->rowVersion,
            ],
        ]);
        self::assertSame('stale_version', $staleResults[0], implode(',', $staleResults));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status, successful_marker FROM payments WHERE payment_id = ?');
        $stmt->execute([$payment->paymentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(PaymentStatus::SUCCESSFUL, $row['status']);
        self::assertSame(1, (int) $row['successful_marker']);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrolments WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM payment_status_history WHERE payment_id = ? AND status_after = ?',
        );
        $stmt->execute([$payment->paymentId, PaymentStatus::SUCCESSFUL]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    private function insertSiblingPendingPayment(int $sourcePaymentId): int
    {
        $container = ApplicationFactory::container('testing');
        $payments = $container->get(PaymentRepository::class);
        $source = $payments->findById($sourcePaymentId);
        self::assertNotNull($source);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $snapshot = new PaymentAmountSnapshot(
            baseFeeMinor: $source->baseFeeMinor,
            gstMinor: $source->gstMinor,
            totalPayableMinor: $source->amountMinor,
            currency: $source->currency,
            gstRatePercent: $source->gstRatePercent,
            courseVersionId: $source->courseVersionId,
            batchId: $source->batchId,
            feeOverrideApplied: $source->feeOverrideApplied,
        );

        $sibling = $payments->insertCreated(
            $source->applicationId,
            $source->userId,
            'PAY-SIB-' . $source->applicationId . '-' . bin2hex(random_bytes(3)),
            $source->provider,
            $snapshot,
            2,
            'pay:app:' . $source->applicationId . ':attempt:2:race',
            $now,
        );
        self::assertTrue($payments->bindProviderOrder(
            $sibling->paymentId,
            'order_sib_' . $sibling->paymentId,
            $sibling->rowVersion,
            $now,
        ));
        $bound = $payments->findById($sibling->paymentId);
        self::assertNotNull($bound);
        self::assertTrue($payments->applyTransition(
            $bound->paymentId,
            PaymentStatus::CREATED,
            PaymentStatus::PENDING,
            $bound->rowVersion,
            $now,
        ));

        return $sibling->paymentId;
    }

    /**
     * @param list<list<string>> $commands
     * @return list<string>
     */
    private function runWorkers(array $commands): array
    {
        $env = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
            'APP_ENV' => 'testing',
            'TOKEN_PEPPER' => $_ENV['TOKEN_PEPPER'] ?? 'testing-token-pepper-not-for-production',
            'OTP_PEPPER' => $_ENV['OTP_PEPPER'] ?? 'testing-otp-pepper-not-for-production',
            'RATE_LIMIT_PEPPER' => $_ENV['RATE_LIMIT_PEPPER'] ?? 'phpunit-rate-limit-pepper',
            'NOTIFICATION_DELIVERY_KEY' => $_ENV['NOTIFICATION_DELIVERY_KEY']
                ?? 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'PAYMENTS_FAKE_GATEWAY' => '1',
            'PAYMENTS_RECONCILE_PENDING_STALE_SECONDS' => '0',
            'RAZORPAY_WEBHOOK_SECRET' => 'local-ci-razorpay-webhook-secret-not-for-production',
        ];

        $processes = [];
        $pipesList = [];
        foreach ($commands as $command) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($command, $descriptors, $pipes, null, $env);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $processes[] = $proc;
            $pipesList[] = $pipes;
        }

        $results = [];
        foreach ($processes as $i => $proc) {
            $stdout = stream_get_contents($pipesList[$i][1]);
            $stderr = stream_get_contents($pipesList[$i][2]);
            fclose($pipesList[$i][1]);
            fclose($pipesList[$i][2]);
            $code = proc_close($proc);
            $line = trim((string) $stdout);
            if ($line === '' && $code !== 0) {
                $line = 'error:' . trim((string) $stderr);
            }
            $results[] = $line;
        }

        return $results;
    }
}
