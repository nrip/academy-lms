<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\RateLimit;

use Academy\Infrastructure\RateLimit\PdoRateLimitStore;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class AtomicRateLimitStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateWp01aTables();
    }

    public function testInsertUpdateResetAndDecisionCount(): void
    {
        $store = new PdoRateLimitStore(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $now->modify('+60 seconds');
        $key = 'test-bucket-' . bin2hex(random_bytes(8));

        $first = $store->incrementAndGetCount($key, 'test.tight', $now, $end);
        self::assertSame(1, $first['hit_count']);

        $second = $store->incrementAndGetCount($key, 'test.tight', $now, $end);
        self::assertSame(2, $second['hit_count']);

        $third = $store->incrementAndGetCount($key, 'test.tight', $now, $end);
        self::assertSame(3, $third['hit_count']);

        // Force window expiry then reset to 1
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare('UPDATE rate_limit_buckets SET window_ends_at = ? WHERE bucket_key = ?')
            ->execute([$now->modify('-1 second')->format('Y-m-d H:i:s.u'), $key]);

        $reset = $store->incrementAndGetCount($key, 'test.tight', $now, $end);
        self::assertSame(1, $reset['hit_count']);
    }

    public function testConcurrencyProducesExactHitCount(): void
    {
        $key = 'concurrent-' . bin2hex(random_bytes(8));
        $workers = 8;
        $script = dirname(__DIR__, 3) . '/tests/Support/rate_limit_worker.php';
        $procs = [];
        $pipes = [];

        for ($i = 0; $i < $workers; ++$i) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $script, $key],
                $descriptors,
                $pipe,
                dirname(__DIR__, 3),
                [
                    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
                    'DB_PORT' => getenv('DB_PORT') ?: '3306',
                    'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
                    'DB_USER' => getenv('DB_USER') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
                ],
            );
            self::assertIsResource($proc);
            $procs[] = $proc;
            $pipes[] = $pipe;
        }

        $counts = [];
        foreach ($procs as $i => $proc) {
            fclose($pipes[$i][0]);
            $out = stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $code = proc_close($proc);
            self::assertSame(0, $code, 'Worker failed: ' . (string) $out);
            $counts[] = (int) trim((string) $out);
        }

        sort($counts);
        self::assertSame(range(1, $workers), $counts);

        $row = DatabaseTestCase::pdo()
            ->query('SELECT hit_count FROM rate_limit_buckets WHERE bucket_key = ' . DatabaseTestCase::pdo()->quote($key))
            ->fetch();
        self::assertSame($workers, (int) $row['hit_count']);
    }
}
