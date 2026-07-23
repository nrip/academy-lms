<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Notifications;

use Academy\Domain\Notifications\NotificationDeliveryStatus;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Infrastructure\Notifications\PdoNotificationDeliveryRepository;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use PHPUnit\Framework\TestCase;

/**
 * Sequential concurrency / fencing proofs for transactional notification deliveries.
 */
final class TransactionalNotificationConcurrencyTest extends TestCase
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

    public function testDuplicateEnsurePendingSameOutboxYieldsOneRow(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $outbox = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes(
            'conc-a',
            $now,
            60,
            [TransactionalNotificationEventTypes::APPLICATION_APPROVED],
            1,
        );
        self::assertCount(1, $claimed);
        $message = $claimed[0];

        $repoA = new PdoNotificationDeliveryRepository(DatabaseTestCase::connectionFactory());
        $repoB = new PdoNotificationDeliveryRepository(DatabaseTestCase::connectionFactory());

        $first = $repoA->ensurePending(
            $message->id,
            $message->eventType,
            $fixture['applicant_user_id'],
            'email',
            'application_approved_payment_pending',
            1,
            hash('sha256', 'a'),
            'a***@example.test',
            $now,
        );
        $second = $repoB->ensurePending(
            $message->id,
            $message->eventType,
            $fixture['applicant_user_id'],
            'email',
            'application_approved_payment_pending',
            1,
            hash('sha256', 'b'),
            'b***@example.test',
            $now,
        );

        self::assertSame($first->notificationDeliveryId, $second->notificationDeliveryId);

        $stmt = DatabaseTestCase::pdo()->prepare(
            'SELECT COUNT(*) FROM notification_deliveries WHERE outbox_message_id = ?',
        );
        $stmt->execute([$message->id]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testOnlyOneClaimForSendWinsLease(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $outbox = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes(
            'conc-claim',
            $now,
            60,
            [TransactionalNotificationEventTypes::APPLICATION_APPROVED],
            1,
        );
        self::assertCount(1, $claimed);
        $message = $claimed[0];

        $repoA = new PdoNotificationDeliveryRepository(DatabaseTestCase::connectionFactory());
        $repoB = new PdoNotificationDeliveryRepository(DatabaseTestCase::connectionFactory());

        $delivery = $repoA->ensurePending(
            $message->id,
            $message->eventType,
            $fixture['applicant_user_id'],
            'email',
            'application_approved_payment_pending',
            1,
            hash('sha256', 'claim'),
            'c***@example.test',
            $now,
        );

        $winner = $repoA->claimForSend(
            $delivery->notificationDeliveryId,
            'worker-a',
            'token-a',
            $now,
            60,
        );
        $loser = $repoB->claimForSend(
            $delivery->notificationDeliveryId,
            'worker-b',
            'token-b',
            $now,
            60,
        );

        self::assertNotNull($winner);
        self::assertSame('worker-a', $winner->leaseOwner);
        self::assertNull($loser);
        self::assertSame(NotificationDeliveryStatus::PROCESSING, $winner->status);
    }
}
