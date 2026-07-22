<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Real multi-process proof that CAS on row_version yields one winner and one conflict.
 */
final class ProfileOptimisticLockConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testConcurrentPersonalUpdateProducesOneSuccessOneConflict(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $worker = dirname(__DIR__, 2) . '/Support/profile_update_worker.php';

        $processes = [];
        $pipesList = [];
        for ($i = 0; $i < 2; ++$i) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $worker, (string) $user['user_id'], '1', 'Worker' . $i],
                $descriptors,
                $pipes,
                null,
                [
                    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
                    'DB_PORT' => getenv('DB_PORT') ?: '3306',
                    'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
                    'DB_USER' => getenv('DB_USER') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
                ],
            );
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
            self::assertSame(0, $status, 'Worker failed: ' . $stderr);
            $results[] = trim((string) $stdout);
        }

        sort($results);
        self::assertSame(['conflict', 'ok'], $results);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT row_version FROM learner_profiles WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }
}
