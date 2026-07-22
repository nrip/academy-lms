<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\TokenConfirmationCleanupService;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Infrastructure\Identity\PdoTokenConfirmationContextRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Real multi-process concurrency proofs for scanner-safe confirmation.
 */
final class TokenConfirmationConcurrencyTest extends TestCase
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

    public function testTwoGetsCreateTwoContextsWithoutConsumingToken(): void
    {
        $issued = $this->issueToken();
        $getWorker = dirname(__DIR__, 2) . '/Support/token_confirm_get_worker.php';

        $results = $this->runWorkers([
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.1'],
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.2'],
        ]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertStringStartsWith('ok:', $result);
        }

        $pdo = DatabaseTestCase::pdo();
        $ctxCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM token_confirmation_contexts WHERE verification_token_id = '
            . (int) $issued['verification_token_id'],
        )->fetchColumn();
        self::assertSame(2, $ctxCount);

        $token = $pdo->prepare(
            'SELECT consumed_at, current_marker FROM verification_tokens WHERE verification_token_id = ?',
        );
        $token->execute([$issued['verification_token_id']]);
        $row = $token->fetch();
        self::assertNull($row['consumed_at']);
        self::assertSame(1, (int) $row['current_marker']);
    }

    public function testTwoPostsWithDifferentCookiesExactlyOneWinner(): void
    {
        $issued = $this->issueToken();
        $getWorker = dirname(__DIR__, 2) . '/Support/token_confirm_get_worker.php';
        $postWorker = dirname(__DIR__, 2) . '/Support/token_confirm_post_worker.php';

        $gets = $this->runWorkers([
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.10'],
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.11'],
        ]);
        $secrets = [];
        foreach ($gets as $line) {
            self::assertStringStartsWith('ok:', $line);
            $secrets[] = substr($line, 3);
        }
        self::assertCount(2, $secrets);
        self::assertNotSame($secrets[0], $secrets[1]);

        $posts = $this->runWorkers([
            [PHP_BINARY, $postWorker, $secrets[0], TokenPurpose::EMAIL_VERIFY],
            [PHP_BINARY, $postWorker, $secrets[1], TokenPurpose::EMAIL_VERIFY],
        ]);
        sort($posts);
        self::assertSame(['conflict', 'ok'], $posts);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT consumed_at FROM verification_tokens WHERE verification_token_id = ?',
        );
        $stmt->execute([$issued['verification_token_id']]);
        self::assertNotNull($stmt->fetchColumn());

        $consumedContexts = (int) $pdo->query(
            'SELECT COUNT(*) FROM token_confirmation_contexts
             WHERE verification_token_id = ' . (int) $issued['verification_token_id']
             . ' AND consumed_at IS NOT NULL',
        )->fetchColumn();
        self::assertSame(1, $consumedContexts);
    }

    public function testScannerGetAfterUserGetStillAllowsUserPost(): void
    {
        $issued = $this->issueToken();
        $getWorker = dirname(__DIR__, 2) . '/Support/token_confirm_get_worker.php';
        $postWorker = dirname(__DIR__, 2) . '/Support/token_confirm_post_worker.php';

        $userGet = $this->runWorkers([
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.20'],
        ]);
        self::assertStringStartsWith('ok:', $userGet[0]);
        $userSecret = substr($userGet[0], 3);

        $scannerGet = $this->runWorkers([
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.21'],
        ]);
        self::assertStringStartsWith('ok:', $scannerGet[0]);

        $post = $this->runWorkers([
            [PHP_BINARY, $postWorker, $userSecret, TokenPurpose::EMAIL_VERIFY],
        ]);
        self::assertSame(['ok'], $post);
    }

    public function testCleanupDoesNotRemoveLiveUnexpiredContext(): void
    {
        $issued = $this->issueToken();
        $getWorker = dirname(__DIR__, 2) . '/Support/token_confirm_get_worker.php';
        $gets = $this->runWorkers([
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.30'],
        ]);
        self::assertStringStartsWith('ok:', $gets[0]);

        $before = (int) DatabaseTestCase::pdo()->query(
            'SELECT COUNT(*) FROM token_confirmation_contexts WHERE consumed_at IS NULL',
        )->fetchColumn();
        self::assertSame(1, $before);

        $cleanup = new TokenConfirmationCleanupService(
            new PdoTokenConfirmationContextRepository(DatabaseTestCase::connectionFactory()),
        );
        self::assertSame(0, $cleanup->run(100));

        $after = (int) DatabaseTestCase::pdo()->query(
            'SELECT COUNT(*) FROM token_confirmation_contexts WHERE consumed_at IS NULL',
        )->fetchColumn();
        self::assertSame(1, $after);
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
            'concurrency@example.test',
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
