<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Outbox;

use Academy\Application\Outbox\OutboxRelayService;
use Academy\Infrastructure\Outbox\InMemoryOutboxTransport;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Infrastructure\Outbox\UnconfiguredOutboxTransport;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OutboxLeaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
    }

    public function testExpiredLeaseIsRetryableAndUnconfiguredTransportDoesNotChurn(): void
    {
        $repo = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $repo->enqueue('t.event', 'agg', '1', ['status' => 'ok'], 'idem-1');

        $unconfigured = new OutboxRelayService(
            $repo,
            new UnconfiguredOutboxTransport(),
            new NullLogger(),
            60,
            10,
            5,
            3600,
        );
        self::assertFalse($unconfigured->transportConfigured());
        self::assertSame(0, $unconfigured->run('worker-a'));
        self::assertSame('pending', DatabaseTestCase::pdo()->query('SELECT status FROM outbox_messages')->fetchColumn());

        $transport = new InMemoryOutboxTransport();
        $relay = new OutboxRelayService($repo, $transport, new NullLogger(), 60, 10, 5, 3600);
        self::assertSame(1, $relay->run('worker-b'));
        self::assertCount(1, $transport->published);
        self::assertSame('published', DatabaseTestCase::pdo()->query('SELECT status FROM outbox_messages')->fetchColumn());
    }

    public function testExpiredProcessingLeaseCanBeReclaimed(): void
    {
        $repo = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $repo->enqueue('t.event', 'agg', '2', ['status' => 'ok'], 'idem-2');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $repo->claim('worker-1', $now, 60, 1);
        self::assertCount(1, $claimed);

        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare('UPDATE outbox_messages SET lock_expires_at = ? WHERE outbox_message_id = ?')
            ->execute([$now->modify('-1 second')->format('Y-m-d H:i:s.u'), $claimed[0]->id]);

        $reclaimed = $repo->claim('worker-2', $now, 60, 1);
        self::assertCount(1, $reclaimed);
        self::assertSame(2, $reclaimed[0]->attemptCount);
    }
}
