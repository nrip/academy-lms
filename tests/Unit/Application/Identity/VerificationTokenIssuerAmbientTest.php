<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Identity;

use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Proves issueInAmbientTx() participates in the caller's existing TransactionManager::run()
 * transaction rather than opening a nested one, and that the whole operation is committed
 * exactly once (as a unit), not incrementally.
 */
final class VerificationTokenIssuerAmbientTest extends TestCase
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

    public function testIssueInAmbientTxSharesTheCallersTransactionWithoutNestedBegin(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var TransactionManager $transactions */
        $transactions = $container->get(TransactionManager::class);
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $user = DatabaseTestCase::applicantFixture();

        $wasInTransactionBeforeIssue = null;
        $wasInTransactionAfterIssue = null;
        $nestedBeginThrew = false;

        $issued = $transactions->run(function (PDO $pdo) use (
            $issuer,
            $user,
            &$wasInTransactionBeforeIssue,
            &$wasInTransactionAfterIssue,
            &$nestedBeginThrew,
        ): array {
            $wasInTransactionBeforeIssue = $pdo->inTransaction();

            $result = $issuer->issueInAmbientTx(
                $user['user_id'],
                TokenPurpose::EMAIL_VERIFY,
                'ambient@example.test',
                new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
            );

            // If issueInAmbientTx had (incorrectly) opened its own transaction, this call
            // would find the ambient PDO already mid-transaction from a *different* begin
            // and PDO would refuse a second concurrent begin on the same handle.
            $wasInTransactionAfterIssue = $pdo->inTransaction();
            try {
                $pdo->beginTransaction();
                $pdo->rollBack();
            } catch (PDOException) {
                $nestedBeginThrew = true;
            }

            return $result;
        });

        self::assertTrue($wasInTransactionBeforeIssue, 'Ambient PDO must already be mid-transaction before issueInAmbientTx runs.');
        self::assertTrue($wasInTransactionAfterIssue, 'issueInAmbientTx must not commit early.');
        self::assertTrue(
            $nestedBeginThrew,
            'A second beginTransaction() on the still-active ambient PDO must fail, confirming no commit occurred inside issueInAmbientTx.',
        );
        self::assertArrayHasKey('verification_token_id', $issued);
        self::assertSame(64, strlen($issued['raw_token']));
    }

    public function testWholeOperationCommitsExactlyOnceNotIncrementally(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var TransactionManager $transactions */
        $transactions = $container->get(TransactionManager::class);
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $user = DatabaseTestCase::applicantFixture();

        // A separate connection/session can only see committed data.
        $observer = DatabaseTestCase::pdo();
        $capturedTokenId = null;
        $visibleBeforeCommit = null;

        $issued = $transactions->run(function (PDO $pdo) use ($issuer, $user, $observer, &$capturedTokenId, &$visibleBeforeCommit): array {
            unset($pdo);
            $result = $issuer->issueInAmbientTx(
                $user['user_id'],
                TokenPurpose::EMAIL_VERIFY,
                'ambient-commit@example.test',
                new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
            );
            $capturedTokenId = $result['verification_token_id'];

            $check = $observer->prepare(
                'SELECT COUNT(*) FROM verification_tokens WHERE verification_token_id = ?',
            );
            $check->execute([$capturedTokenId]);
            $visibleBeforeCommit = (int) $check->fetchColumn();

            return $result;
        });

        self::assertSame(0, $visibleBeforeCommit, 'The row must not be visible to another session before the ambient transaction commits.');

        $check = $observer->prepare('SELECT COUNT(*) FROM verification_tokens WHERE verification_token_id = ?');
        $check->execute([$capturedTokenId]);
        self::assertSame(1, (int) $check->fetchColumn(), 'The row must be visible after the single ambient commit.');
        self::assertSame($capturedTokenId, $issued['verification_token_id']);
    }
}
