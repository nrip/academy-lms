<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Scheduler;

use Academy\Infrastructure\Scheduler\PdoSchedulerLock;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class SchedulerLockTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
    }

    public function testAcquireRenewRelease(): void
    {
        $lock = new PdoSchedulerLock(DatabaseTestCase::connectionFactory());
        self::assertTrue($lock->acquire('job_a', 'worker-1', 30));
        self::assertFalse($lock->acquire('job_a', 'worker-2', 30));
        self::assertTrue($lock->renew('job_a', 'worker-1', 30));
        self::assertFalse($lock->renew('job_a', 'worker-2', 30));
        self::assertTrue($lock->release('job_a', 'worker-1'));
        self::assertFalse($lock->release('job_a', 'worker-2'));
        self::assertTrue($lock->acquire('job_a', 'worker-2', 30));
    }

    public function testMultiProcessConcurrencyOwnershipAndExpiry(): void
    {
        $lockName = 'concurrent-job-' . bin2hex(random_bytes(4));
        $script = dirname(__DIR__, 3) . '/tests/Support/scheduler_lock_worker.php';
        $env = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
        ];

        $workers = 6;
        $results = [];
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < $workers; ++$i) {
            $workerId = 'worker-' . $i;
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $script, $lockName, $workerId, '30', 'acquire'],
                $descriptors,
                $pipe,
                dirname(__DIR__, 3),
                $env,
            );
            self::assertIsResource($proc);
            $procs[] = $proc;
            $pipes[] = $pipe;
            $results[$workerId] = null;
        }

        $acquiredBy = [];
        foreach ($procs as $i => $proc) {
            fclose($pipes[$i][0]);
            $out = trim((string) stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $code = proc_close($proc);
            self::assertSame(0, $code, 'Acquire worker failed');
            $workerId = 'worker-' . $i;
            $ok = $out === '1';
            $results[$workerId] = $ok;
            if ($ok) {
                $acquiredBy[] = $workerId;
            }
        }

        self::assertCount(1, $acquiredBy, 'Exactly one process must acquire the lock');
        $owner = $acquiredBy[0];

        // Non-owners cannot renew or release
        $nonOwner = $owner === 'worker-0' ? 'worker-1' : 'worker-0';
        self::assertSame('0', $this->runWorker($script, $env, $lockName, $nonOwner, '30', 'renew'));
        self::assertSame('0', $this->runWorker($script, $env, $lockName, $nonOwner, '30', 'release'));
        self::assertSame('1', $this->runWorker($script, $env, $lockName, $owner, '30', 'renew'));

        // Expire the lock, then a new process can acquire
        $pdo = DatabaseTestCase::pdo();
        $past = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 second');
        $pdo->prepare('UPDATE scheduler_locks SET locked_until = ? WHERE lock_name = ?')
            ->execute([$past->format('Y-m-d H:i:s.u'), $lockName]);

        self::assertSame('0', $this->runWorker($script, $env, $lockName, $owner, '30', 'renew'));
        self::assertSame('1', $this->runWorker($script, $env, $lockName, 'worker-new', '30', 'acquire'));
        self::assertSame('0', $this->runWorker($script, $env, $lockName, $owner, '30', 'release'));
        self::assertSame('1', $this->runWorker($script, $env, $lockName, 'worker-new', '30', 'release'));
    }

    /**
     * @param array<string, string> $env
     */
    private function runWorker(
        string $script,
        array $env,
        string $lockName,
        string $lockedBy,
        string $ttl,
        string $action,
    ): string {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $script, $lockName, $lockedBy, $ttl, $action],
            $descriptors,
            $pipes,
            dirname(__DIR__, 3),
            $env,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(0, $code, 'Worker failed: ' . (string) $err);

        return $out;
    }
}
