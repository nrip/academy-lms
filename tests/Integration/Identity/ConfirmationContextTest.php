<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\TokenConfirmationCleanupService;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Infrastructure\Identity\PdoTokenConfirmationContextRepository;
use Academy\Infrastructure\Identity\PdoVerificationTokenRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class ConfirmationContextTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testMultipleContextsPerTokenConsumeAndCleanup(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $tokens = new PdoVerificationTokenRepository(DatabaseTestCase::connectionFactory());
        $contexts = new PdoTokenConfirmationContextRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $tokenId = $tokens->insertPendingCurrent(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            hash('sha256', 'ctx-parent'),
            $now->modify('+1 hour'),
            $now,
        );

        $ctx1 = $contexts->insert(
            hash('sha256', 'secret-1'),
            $tokenId,
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            $now->modify('+15 minutes'),
            $now,
        );
        $ctx2 = $contexts->insert(
            hash('sha256', 'secret-2'),
            $tokenId,
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            $now->modify('+15 minutes'),
            $now,
        );
        self::assertNotSame($ctx1, $ctx2);

        self::assertTrue($contexts->markConsumed($ctx1, $now));
        self::assertFalse($contexts->markConsumed($ctx1, $now));

        $pdo = DatabaseTestCase::pdo();
        // Aged expired context (past 7-day retention cutoff relative to cleanup service).
        $oldExpires = $now->modify('-8 days');
        $pdo->prepare(
            'INSERT INTO token_confirmation_contexts (
                confirmation_hash, verification_token_id, user_id, purpose, expires_at, consumed_at, created_at
            ) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        )->execute([
            hash('sha256', 'aged-expired'),
            $tokenId,
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            $oldExpires->format('Y-m-d H:i:s.u'),
            $oldExpires->format('Y-m-d H:i:s.u'),
        ]);

        // Aged consumed context.
        $pdo->prepare(
            'INSERT INTO token_confirmation_contexts (
                confirmation_hash, verification_token_id, user_id, purpose, expires_at, consumed_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            hash('sha256', 'aged-consumed'),
            $tokenId,
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            $now->modify('+1 hour')->format('Y-m-d H:i:s.u'),
            $now->modify('-8 days')->format('Y-m-d H:i:s.u'),
            $now->modify('-9 days')->format('Y-m-d H:i:s.u'),
        ]);

        $liveHash = hash('sha256', 'live-unexpired');
        $liveId = $contexts->insert(
            $liveHash,
            $tokenId,
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            $now->modify('+15 minutes'),
            $now,
        );

        $cleanup = new TokenConfirmationCleanupService($contexts);
        $deleted = $cleanup->run(100);
        self::assertGreaterThanOrEqual(2, $deleted);

        $remainingLive = $contexts->findByHashForUpdate($liveHash);
        self::assertNotNull($remainingLive);
        self::assertSame($liveId, $remainingLive->tokenConfirmationContextId);
        self::assertNull($remainingLive->consumedAt);

        // Live unexpired unconsumed must never be deleted by cleanup.
        self::assertSame(0, $cleanup->run(100));
        self::assertNotNull($contexts->findByHashForUpdate($liveHash));
    }
}
