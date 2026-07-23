<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Notifications;

use Academy\Application\Audit\AuditService;
use Academy\Application\Notifications\NotificationContextResolver;
use Academy\Application\Notifications\NotificationTemplateRenderer;
use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Application\Notifications\TransactionalNotificationTemplateRegistry;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\NotificationDeliveryRepository;
use Academy\Domain\Notifications\NotificationDeliveryStatus;
use Academy\Domain\Notifications\NotificationFailureCategory;
use Academy\Domain\Notifications\NotificationRetryPolicy;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Notifications\PdoNotificationDeliveryRepository;
use Academy\Infrastructure\Notifications\RecordingEmailAdapter;
use Academy\Infrastructure\Notifications\UnavailableEmailAdapter;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TransactionalNotificationDeliveryTest extends TestCase
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

    public function testWorkerDeliversApplicationApprovedFromPaymentPendingFixture(): void
    {
        PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $recording = $container->get(RecordingEmailAdapter::class);

        $processed = $container->get(TransactionalNotificationDeliveryWorker::class)->run('notif-worker-1', 20);
        self::assertGreaterThanOrEqual(1, $processed);

        $pdo = DatabaseTestCase::pdo();
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM notification_deliveries
             WHERE source_event_type = 'application.approved' AND status = 'delivered'",
        )->fetchColumn();
        self::assertSame(1, $count);
        self::assertNotEmpty($recording->recorded());
    }

    public function testWorkerIdempotentOnSameOutbox(): void
    {
        PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $worker = $container->get(TransactionalNotificationDeliveryWorker::class);

        $worker->run('notif-idem-1', 20);
        $worker->run('notif-idem-2', 20);

        $pdo = DatabaseTestCase::pdo();
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM notification_deliveries WHERE source_event_type = 'application.approved'",
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testRecordingAdapterMarksDelivered(): void
    {
        PaymentTestFixture::seedPaymentPendingApplication();
        $recording = new RecordingEmailAdapter();
        $worker = $this->buildWorker($recording);

        $worker->run('notif-rec-1', 20);

        $row = DatabaseTestCase::pdo()->query(
            "SELECT status, provider_message_id FROM notification_deliveries
             WHERE source_event_type = 'application.approved' LIMIT 1",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(NotificationDeliveryStatus::DELIVERED, $row['status']);
        self::assertNotNull($row['provider_message_id']);
        self::assertGreaterThanOrEqual(1, count($recording->recorded()));
    }

    public function testUnavailableAdapterRetriesThenDead(): void
    {
        PaymentTestFixture::seedPaymentPendingApplication();
        $policy = new NotificationRetryPolicy(2, 1, 1);
        $worker = $this->buildWorker(new UnavailableEmailAdapter(), $policy);

        $worker->run('notif-fail-1', 20);

        $pdo = DatabaseTestCase::pdo();
        $row = $pdo->query(
            "SELECT status, attempt_count, failure_category FROM notification_deliveries
             WHERE source_event_type = 'application.approved' LIMIT 1",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(NotificationDeliveryStatus::FAILED, $row['status']);
        self::assertSame(NotificationFailureCategory::PROVIDER_TRANSIENT, $row['failure_category']);

        $pdo->exec('UPDATE notification_deliveries SET next_attempt_at = NULL WHERE status = \'failed\'');

        $worker->run('notif-fail-2', 20);

        $row = $pdo->query(
            "SELECT status, attempt_count FROM notification_deliveries
             WHERE source_event_type = 'application.approved' LIMIT 1",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(NotificationDeliveryStatus::DEAD, $row['status']);
        self::assertGreaterThanOrEqual(2, (int) $row['attempt_count']);
    }

    public function testStaleLeaseCannotOverwriteDelivered(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $outbox = new \Academy\Infrastructure\Outbox\PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes(
            'lease-owner',
            $now,
            60,
            [TransactionalNotificationEventTypes::APPLICATION_APPROVED],
            1,
        );
        self::assertCount(1, $claimed);
        $message = $claimed[0];

        $deliveries = new PdoNotificationDeliveryRepository(DatabaseTestCase::connectionFactory());
        $delivery = $deliveries->ensurePending(
            $message->id,
            $message->eventType,
            $fixture['applicant_user_id'],
            'email',
            'application_approved_payment_pending',
            1,
            hash('sha256', 'test'),
            't***@example.test',
            $now,
        );

        $claimedDelivery = $deliveries->claimForSend(
            $delivery->notificationDeliveryId,
            'worker-a',
            'token-a',
            $now,
            60,
        );
        self::assertNotNull($claimedDelivery);

        self::assertTrue(
            $deliveries->markDelivered(
                $delivery->notificationDeliveryId,
                'worker-a',
                'token-a',
                'prov-ok',
                $now,
            ),
        );

        self::assertFalse(
            $deliveries->markDelivered(
                $delivery->notificationDeliveryId,
                'worker-stale',
                'wrong-token',
                'prov-stale',
                $now,
            ),
        );

        $final = $deliveries->findById($delivery->notificationDeliveryId);
        self::assertNotNull($final);
        self::assertSame(NotificationDeliveryStatus::DELIVERED, $final->status);
        self::assertSame('prov-ok', $final->providerMessageId);
    }

    private function buildWorker(
        EmailDeliveryPort $email,
        ?NotificationRetryPolicy $policy = null,
    ): TransactionalNotificationDeliveryWorker {
        $container = ApplicationFactory::container('testing');

        return new TransactionalNotificationDeliveryWorker(
            $container->get(OutboxRepository::class),
            $container->get(NotificationDeliveryRepository::class),
            $container->get(NotificationContextResolver::class),
            $container->get(TransactionalNotificationTemplateRegistry::class),
            $container->get(NotificationTemplateRenderer::class),
            $email,
            $policy ?? $container->get(NotificationRetryPolicy::class),
            $container->get(TransactionManager::class),
            $container->get(AuditService::class),
            $container->get(LoggerInterface::class),
            60,
        );
    }
}
