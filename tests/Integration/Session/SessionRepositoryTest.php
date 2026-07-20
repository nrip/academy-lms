<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Session;

use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SessionRepositoryTest extends TestCase
{
    private SessionService $sessions;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
        $this->sessions = new SessionService(
            new PdoSessionRepository(DatabaseTestCase::connectionFactory()),
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

        $repo = new PdoSessionRepository(DatabaseTestCase::connectionFactory());
        self::assertNull($repo->findByTokenHash(hash('sha256', $oldToken)));
        self::assertNotNull($repo->findByTokenHash(hash('sha256', $rotated['raw_token'])));
    }
}
