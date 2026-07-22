<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Notifications;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Notifications\DeliveryFinaliser;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Notifications\DeliveryStatus;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Identity\PdoVerificationChallengeRepository;
use Academy\Infrastructure\Identity\PdoVerificationTokenRepository;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class DeliveryFinaliserTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testDeliveredClearsCiphertextAndPublishes(): void
    {
        $issued = $this->issueToken();
        $factory = DatabaseTestCase::connectionFactory();
        $outbox = new PdoOutboxRepository($factory);
        $tokens = new PdoVerificationTokenRepository($factory);
        $finaliser = $this->finaliser($factory, $outbox, $tokens);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes(
            'worker-a',
            $now,
            60,
            IdentityNotificationEventTypes::all(),
            1,
        );
        self::assertCount(1, $claimed);

        self::assertTrue($finaliser->finalizeDelivered('token', $issued['verification_token_id'], $claimed[0], 'prov-ok'));

        $row = $tokens->findById($issued['verification_token_id']);
        self::assertSame(DeliveryStatus::DELIVERED, $row?->deliveryStatus);
        self::assertNull($row?->deliveryCiphertext);

        $status = DatabaseTestCase::pdo()
            ->query('SELECT status FROM outbox_messages WHERE outbox_message_id = ' . (int) $claimed[0]->id)
            ->fetchColumn();
        self::assertSame('published', $status);
    }

    public function testTerminalPath(): void
    {
        $issued = $this->issueToken();
        $factory = DatabaseTestCase::connectionFactory();
        $outbox = new PdoOutboxRepository($factory);
        $tokens = new PdoVerificationTokenRepository($factory);
        $finaliser = $this->finaliser($factory, $outbox, $tokens);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes('worker-t', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertTrue($finaliser->finalizeTerminal('token', $issued['verification_token_id'], $claimed[0], 'delivery_failed:X'));

        $row = $tokens->findById($issued['verification_token_id']);
        self::assertSame(DeliveryStatus::TERMINAL, $row?->deliveryStatus);
        self::assertNull($row?->deliveryCiphertext);
    }

    public function testRetryPathLeavesCiphertext(): void
    {
        $issued = $this->issueToken();
        $factory = DatabaseTestCase::connectionFactory();
        $outbox = new PdoOutboxRepository($factory);
        $tokens = new PdoVerificationTokenRepository($factory);

        $before = $tokens->findById($issued['verification_token_id']);
        self::assertNotNull($before?->deliveryCiphertext);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes('worker-r', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimed);

        self::assertTrue($outbox->markRetryOrDead(
            $claimed[0]->id,
            $claimed[0]->lockedBy,
            $claimed[0]->claimToken,
            $claimed[0]->attemptCount,
            10,
            'delivery_failed:Temp',
            $now,
            5,
        ));

        $after = $tokens->findById($issued['verification_token_id']);
        self::assertSame(DeliveryStatus::PENDING, $after?->deliveryStatus);
        self::assertNotNull($after?->deliveryCiphertext);
        self::assertSame($before->deliveryCiphertext, $after->deliveryCiphertext);
    }

    public function testDeliveredPreventsResend(): void
    {
        $issued = $this->issueToken();
        $factory = DatabaseTestCase::connectionFactory();
        $outbox = new PdoOutboxRepository($factory);
        $tokens = new PdoVerificationTokenRepository($factory);
        $finaliser = $this->finaliser($factory, $outbox, $tokens);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimed = $outbox->claimByEventTypes('worker-1', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertTrue($finaliser->finalizeDelivered('token', $issued['verification_token_id'], $claimed[0], 'first'));

        // Second finalize with stale claim fails; status stays delivered.
        $this->expectException(DomainRuleException::class);
        $finaliser->finalizeDelivered('token', $issued['verification_token_id'], $claimed[0], 'second');
    }

    public function testWorkerDeliveredIdempotentRepublishDoesNotClearAlreadyCleared(): void
    {
        $issued = $this->issueToken();
        $container = ApplicationFactory::container('testing');
        /** @var IdentityNotificationDeliveryWorker $worker */
        $worker = $container->get(IdentityNotificationDeliveryWorker::class);
        self::assertSame(1, $worker->run('w1', 10));

        $tokens = new PdoVerificationTokenRepository(DatabaseTestCase::connectionFactory());
        self::assertSame(DeliveryStatus::DELIVERED, $tokens->findById($issued['verification_token_id'])?->deliveryStatus);

        // Enqueue a duplicate identity event pointing at same record and claim+process.
        $outbox = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $outbox->enqueue(
            IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND,
            'verification_token',
            (string) $issued['verification_token_id'],
            ['verification_token_id' => $issued['verification_token_id'], 'purpose' => TokenPurpose::EMAIL_VERIFY],
            'dup:' . $issued['verification_token_id'] . ':' . bin2hex(random_bytes(4)),
        );
        self::assertSame(1, $worker->run('w2', 10));
        self::assertSame(DeliveryStatus::DELIVERED, $tokens->findById($issued['verification_token_id'])?->deliveryStatus);
    }

    /**
     * @return array{verification_token_id: int, raw_token: string}
     */
    private function issueToken(): array
    {
        $user = DatabaseTestCase::applicantFixture();
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);

        return $issuer->issue(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            'deliver@example.test',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
    }

    private function finaliser(
        \Academy\Infrastructure\Database\ConnectionFactory $factory,
        PdoOutboxRepository $outbox,
        PdoVerificationTokenRepository $tokens,
    ): DeliveryFinaliser {
        return new DeliveryFinaliser(
            new TransactionManager($factory),
            $outbox,
            $tokens,
            new PdoVerificationChallengeRepository($factory),
            new AuditService(new PdoAuditWriter($factory), new AuditRedactor()),
            10,
        );
    }
}
