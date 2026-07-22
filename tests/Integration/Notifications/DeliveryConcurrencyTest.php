<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Notifications;

use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Real multi-process concurrency proofs for delivery finalisation fencing.
 */
final class DeliveryConcurrencyTest extends TestCase
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

    public function testValidVersusStaleFinalisersRace(): void
    {
        $issued = $this->issueToken();
        $outbox = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $claimedA = $outbox->claimByEventTypes('worker-a', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimedA);
        $messageA = $claimedA[0];

        DatabaseTestCase::pdo()->prepare(
            'UPDATE outbox_messages SET lock_expires_at = ? WHERE outbox_message_id = ?',
        )->execute([$now->modify('-1 second')->format('Y-m-d H:i:s.u'), $messageA->id]);

        $claimedB = $outbox->claimByEventTypes('worker-b', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimedB);
        $messageB = $claimedB[0];

        $worker = dirname(__DIR__, 2) . '/Support/delivery_finalise_worker.php';
        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                'token',
                (string) $issued['verification_token_id'],
                (string) $messageA->id,
                $messageA->lockedBy,
                $messageA->claimToken,
                (string) $messageA->attemptCount,
                'stale-prov',
            ],
            [
                PHP_BINARY,
                $worker,
                'token',
                (string) $issued['verification_token_id'],
                (string) $messageB->id,
                $messageB->lockedBy,
                $messageB->claimToken,
                (string) $messageB->attemptCount,
                'valid-prov',
            ],
        ]);
        sort($results);
        self::assertSame(['ok', 'stale'], $results);

        $row = DatabaseTestCase::pdo()->prepare(
            'SELECT delivery_status, delivery_ciphertext, provider_message_id
             FROM verification_tokens WHERE verification_token_id = ?',
        );
        $row->execute([$issued['verification_token_id']]);
        $token = $row->fetch();
        self::assertSame('delivered', $token['delivery_status']);
        self::assertNull($token['delivery_ciphertext']);
        self::assertSame('valid-prov', $token['provider_message_id']);
    }

    public function testLeaseLossAfterProviderAcceptStaleFinalisationRollsBack(): void
    {
        $issued = $this->issueToken();
        $outbox = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $claimedA = $outbox->claimByEventTypes('worker-a', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimedA);
        $messageA = $claimedA[0];

        // Simulate provider accept by A, then lease loss before finalise.
        DatabaseTestCase::pdo()->prepare(
            'UPDATE outbox_messages SET lock_expires_at = ? WHERE outbox_message_id = ?',
        )->execute([$now->modify('-1 second')->format('Y-m-d H:i:s.u'), $messageA->id]);

        $claimedB = $outbox->claimByEventTypes('worker-b', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimedB);

        $worker = dirname(__DIR__, 2) . '/Support/delivery_finalise_worker.php';
        $staleOnly = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                'token',
                (string) $issued['verification_token_id'],
                (string) $messageA->id,
                $messageA->lockedBy,
                $messageA->claimToken,
                (string) $messageA->attemptCount,
                'provider-accepted-but-stale',
            ],
        ]);
        self::assertSame(['stale'], $staleOnly);

        $row = DatabaseTestCase::pdo()->prepare(
            'SELECT delivery_status, delivery_ciphertext FROM verification_tokens WHERE verification_token_id = ?',
        );
        $row->execute([$issued['verification_token_id']]);
        $token = $row->fetch();
        self::assertSame('pending', $token['delivery_status']);
        self::assertNotNull($token['delivery_ciphertext'], 'Stale finalisation must roll back and leave ciphertext.');

        $messageB = $claimedB[0];
        $winner = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                'token',
                (string) $issued['verification_token_id'],
                (string) $messageB->id,
                $messageB->lockedBy,
                $messageB->claimToken,
                (string) $messageB->attemptCount,
                'winner-prov',
            ],
        ]);
        self::assertSame(['ok'], $winner);
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
            'delivery-concurrency@example.test',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
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
        foreach ($processes as $index => $proc) {
            $stdout = stream_get_contents($pipesList[$index][1]);
            $stderr = stream_get_contents($pipesList[$index][2]);
            fclose($pipesList[$index][1]);
            fclose($pipesList[$index][2]);
            $status = proc_close($proc);
            self::assertSame(0, $status, 'Worker failed: ' . $stderr . ' / ' . $stdout);
            $results[] = trim((string) $stdout);
        }

        return $results;
    }
}
