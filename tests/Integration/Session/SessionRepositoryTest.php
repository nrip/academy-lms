<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Session;

use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SessionRepositoryTest extends TestCase
{
    private SessionService $sessions;
    private PdoSessionRepository $repo;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
        $this->repo = new PdoSessionRepository(DatabaseTestCase::connectionFactory());
        $this->sessions = new SessionService(
            $this->repo,
            new CsrfTokenManager(),
            new NullLogger(),
            ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
            ['idle_seconds' => 900, 'absolute_seconds' => 28800],
            300,
        );
    }

    public function testCreateStoresHashedTokenAndCsrfOnly(): void
    {
        $created = $this->sessions->loadOrCreate(null, '127.0.0.1', 'phpunit');
        $pdo = DatabaseTestCase::pdo();
        $row = $pdo->query('SELECT session_token_hash, csrf_token_hash, payload FROM sessions')->fetch();
        self::assertSame(hash('sha256', $created['raw_token']), $row['session_token_hash']);
        self::assertSame(hash('sha256', $created['raw_csrf']), $row['csrf_token_hash']);
        self::assertStringNotContainsString($created['raw_token'], (string) $row['payload']);
        self::assertStringNotContainsString($created['raw_csrf'], (string) $row['payload']);
    }

    public function testRegenerateInvalidatesOldTokenImmediately(): void
    {
        $created = $this->sessions->loadOrCreate(null, null, null);
        $oldToken = $created['raw_token'];
        $rotated = $this->sessions->regenerate($created['record']);

        self::assertNull($this->repo->findByTokenHash(hash('sha256', $oldToken)));
        self::assertNotNull($this->repo->findByTokenHash(hash('sha256', $rotated['raw_token'])));
    }

    public function testIndependentSessionWritesSucceed(): void
    {
        $created = $this->sessions->loadOrCreate(null, null, null);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->repo->touch(
            $created['record']->sessionId,
            $now,
            $now->modify('+1800 seconds'),
        );
        $this->repo->revoke($created['record']->sessionId, $now);
        $row = DatabaseTestCase::pdo()->query(
            'SELECT revoked_at FROM sessions WHERE session_id = ' . (int) $created['record']->sessionId,
        )->fetch();
        self::assertNotNull($row['revoked_at']);
    }

    public function testAmbientTransactionRejectsSessionWritesAndRemainsCallerOwned(): void
    {
        $factory = DatabaseTestCase::connectionFactory();
        $repo = new PdoSessionRepository($factory);
        $pdo = $factory->connection();

        $pdo->beginTransaction();
        self::assertTrue($pdo->inTransaction());

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $repo->create(
                hash('sha256', 'ambient-token'),
                hash('sha256', 'ambient-csrf'),
                [],
                $now,
                $now->modify('+1 hour'),
                $now->modify('+30 minutes'),
                null,
                null,
            );
            self::fail('Expected ambient transaction rejection.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('ambient transaction', $exception->getMessage());
        }

        self::assertTrue($pdo->inTransaction(), 'Caller must still own the ambient transaction');

        // Caller can still write inside their own transaction.
        $marker = 'ambient-marker-' . bin2hex(random_bytes(4));
        $pdo->prepare(
            'INSERT INTO scheduler_locks (lock_name, locked_until, locked_by, updated_at) VALUES (?, ?, ?, ?)',
        )->execute([
            $marker,
            $now->modify('+10 seconds')->format('Y-m-d H:i:s.u'),
            'ambient-test',
            $now->format('Y-m-d H:i:s.u'),
        ]);
        $pdo->commit();

        $count = (int) DatabaseTestCase::pdo()
            ->query('SELECT COUNT(*) FROM scheduler_locks WHERE lock_name = ' . DatabaseTestCase::pdo()->quote($marker))
            ->fetchColumn();
        self::assertSame(1, $count);

        $sessionCount = (int) DatabaseTestCase::pdo()
            ->query('SELECT COUNT(*) FROM sessions WHERE session_token_hash = ' . DatabaseTestCase::pdo()->quote(hash('sha256', 'ambient-token')))
            ->fetchColumn();
        self::assertSame(0, $sessionCount);
    }

    public function testReadOnlyLoadAllowedDuringAmbientTransaction(): void
    {
        $created = $this->sessions->loadOrCreate(null, null, null);
        $factory = DatabaseTestCase::connectionFactory();
        $repo = new PdoSessionRepository($factory);
        $pdo = $factory->connection();
        $pdo->beginTransaction();
        try {
            $found = $repo->findByTokenHash(hash('sha256', $created['raw_token']));
            self::assertNotNull($found);
        } finally {
            $pdo->rollBack();
        }
    }
}
