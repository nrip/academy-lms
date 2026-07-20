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

        self::assertSame(1, $store->incrementAndGetCount($key, 'test.tight', $now, $end));
        self::assertSame(2, $store->incrementAndGetCount($key, 'test.tight', $now, $end));
        self::assertSame(3, $store->incrementAndGetCount($key, 'test.tight', $now, $end));

        // Force window expiry then reset to 1
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare('UPDATE rate_limit_buckets SET window_ends_at = ? WHERE bucket_key = ?')
            ->execute([$now->modify('-1 second')->format('Y-m-d H:i:s.u'), $key]);

        self::assertSame(1, $store->incrementAndGetCount($key, 'test.tight', $now, $end));
    }

    public function testUnconditionalUpdatedAtKeepsUpdateRowCountSemantics(): void
    {
        $pdo = DatabaseTestCase::pdo();
        $key = 'rowcount-' . bin2hex(random_bytes(8));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $end = $now->modify('+60 seconds')->format('Y-m-d H:i:s.u');

        // First upsert = INSERT → ROW_COUNT() = 1
        $insert = $pdo->prepare(
            'INSERT INTO rate_limit_buckets (
                bucket_key, policy_key, hit_count, window_starts_at, window_ends_at, created_at, updated_at
            ) VALUES (?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                hit_count = LAST_INSERT_ID(hit_count + 1),
                updated_at = VALUES(updated_at)',
        );
        $insert->execute([$key, 'test.tight', $nowStr, $end, $nowStr, $nowStr]);
        $first = $pdo->query('SELECT IF(ROW_COUNT() = 1, 1, LAST_INSERT_ID()) AS hit_count');
        self::assertNotFalse($first);
        self::assertSame(1, (int) $first->fetchColumn());

        // Second upsert = UPDATE with unconditional updated_at → ROW_COUNT() = 2
        $later = $now->modify('+1 second')->format('Y-m-d H:i:s.u');
        $update = $pdo->prepare(
            'INSERT INTO rate_limit_buckets (
                bucket_key, policy_key, hit_count, window_starts_at, window_ends_at, created_at, updated_at
            ) VALUES (?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                hit_count = LAST_INSERT_ID(hit_count + 1),
                updated_at = ?',
        );
        $update->execute([$key, 'test.tight', $nowStr, $end, $later, $later, $later]);
        $second = $pdo->query('SELECT IF(ROW_COUNT() = 1, 1, LAST_INSERT_ID()) AS hit_count, ROW_COUNT() AS affected');
        self::assertNotFalse($second);
        $row = $second->fetch();
        self::assertSame(2, (int) $row['hit_count']);
        self::assertSame(2, (int) $row['affected']);

        // Contrast: assigning identical column values yields ROW_COUNT() = 0 (decision branch would break
        // without the load-bearing unconditional updated_at write on the real upsert path).
        $pdo->prepare(
            'UPDATE rate_limit_buckets
             SET hit_count = hit_count, updated_at = updated_at
             WHERE bucket_key = ?',
        )->execute([$key]);
        $noop = $pdo->query('SELECT ROW_COUNT() AS affected');
        self::assertNotFalse($noop);
        self::assertSame(0, (int) $noop->fetchColumn());
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
