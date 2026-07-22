<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Outbox;

use Academy\Application\Outbox\OutboxRelayService;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Infrastructure\Outbox\InMemoryOutboxTransport;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OutboxEventFilterClaimTest extends TestCase
{
    private PdoOutboxRepository $repo;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
        $this->repo = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
    }

    public function testClaimByEventTypesOnlyIdentity(): void
    {
        $this->repo->enqueue(
            IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND,
            'verification_token',
            '1',
            ['verification_token_id' => 1, 'purpose' => 'email_verify'],
            'id-email-1',
        );
        $this->repo->enqueue('other.event', 'agg', '2', ['x' => 1], 'id-other-1');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $this->repo->claimByEventTypes(
            'identity-worker',
            $now,
            60,
            IdentityNotificationEventTypes::all(),
            10,
        );

        self::assertCount(1, $claimed);
        self::assertSame(IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND, $claimed[0]->eventType);
    }

    public function testClaimExcludingEventTypesSkipsIdentity(): void
    {
        $this->repo->enqueue(
            IdentityNotificationEventTypes::PASSWORD_RESET_SEND,
            'verification_token',
            '3',
            ['verification_token_id' => 3, 'purpose' => 'password_reset'],
            'id-reset-1',
        );
        $this->repo->enqueue('billing.invoice', 'invoice', '9', ['n' => 1], 'id-bill-1');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $this->repo->claimExcludingEventTypes(
            'relay-worker',
            $now,
            60,
            IdentityNotificationEventTypes::all(),
            10,
        );

        self::assertCount(1, $claimed);
        self::assertSame('billing.invoice', $claimed[0]->eventType);
    }

    public function testOutboxRelayServiceUsesExclude(): void
    {
        $this->repo->enqueue(
            IdentityNotificationEventTypes::MOBILE_OTP_SEND,
            'verification_challenge',
            '4',
            ['verification_challenge_id' => 4, 'channel' => 'sms'],
            'id-otp-1',
        );
        $this->repo->enqueue('generic.relay', 'agg', '5', ['ok' => true], 'id-gen-1');

        $transport = new InMemoryOutboxTransport();
        $relay = new OutboxRelayService($this->repo, $transport, new NullLogger(), 60, 10, 5, 3600);
        self::assertSame(1, $relay->run('relay-a'));
        self::assertCount(1, $transport->published);
        self::assertSame('generic.relay', $transport->published[0]['event_type']);

        $pdo = DatabaseTestCase::pdo();
        $identityStatus = $pdo->query(
            "SELECT status FROM outbox_messages WHERE event_type = 'identity.mobile_otp.send'",
        )->fetchColumn();
        self::assertSame('pending', $identityStatus);
    }
}
