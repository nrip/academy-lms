<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Admissions;

use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Real multi-process race for draft Application creation: two concurrent
 * requests for the same (user_id, batch_id) must yield exactly one row,
 * enforced by the UNIQUE(user_id, batch_id) constraint + PDOException
 * duplicate-key recovery in DraftApplicationService::createDraft(), not by
 * any application-level locking.
 */
final class DraftApplicationConcurrencyTest extends TestCase
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

    public function testConcurrentDraftCreationForSameUserAndBatchYieldsExactlyOneApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $user = DatabaseTestCase::applicantFixture();
        $worker = dirname(__DIR__, 2) . '/Support/draft_application_worker.php';

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, (string) $user['user_id'], (string) $user['auth_version'], (string) $seeded['batch_id']],
            [PHP_BINARY, $worker, (string) $user['user_id'], (string) $user['auth_version'], (string) $seeded['batch_id']],
        ]);

        $created = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'created:')));
        $conflicts = array_values(array_filter($results, static fn (string $r): bool => $r === 'conflict'));

        // Both workers race the availability check + insertDraft(); the loser either
        // observes the winner's row via findByUserAndBatch() (and returns it as
        // "created:<same id>") or hits the unique-key PDOException path and resolves
        // to the same row. A real ConflictException("conflict") only happens if the
        // resolved row is not itself a draft, which cannot occur here since draft
        // creation is the only status reachable in this slice.
        self::assertSame(2, count($created) + count($conflicts));
        self::assertSame([], $conflicts, 'No competing draft was submitted, so a conflict indicates a resolution bug.');

        $applicationIds = array_map(static fn (string $r): string => substr($r, strlen('created:')), $created);
        self::assertCount(1, array_unique($applicationIds), 'Both workers must resolve to the same application id.');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE user_id = ? AND batch_id = ?');
        $stmt->execute([$user['user_id'], $seeded['batch_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
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
