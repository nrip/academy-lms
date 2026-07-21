<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\RBAC;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Real multi-process concurrency proof for duplicate concurrent assign.
 *
 * Optional future hardening (not required unless shared locking changes):
 * - concurrent revoke-vs-revoke
 * - concurrent reassign-vs-reassign
 */
final class RoleAssignmentConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testConcurrentAssignProducesOneSuccessOneConflict(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'rbac.concurrent@example.test',
            '+916144444444',
            [],
        );
        $initial = DatabaseTestCase::authVersion($user['user_id']);
        $worker = dirname(__DIR__, 2) . '/Support/role_assign_worker.php';

        $processes = [];
        $pipesList = [];
        for ($i = 0; $i < 2; ++$i) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $worker, (string) $user['user_id'], RoleKeys::APPLICANT],
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
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_key = ? AND ur.current_marker = 1',
        );
        $stmt->execute([$user['user_id'], RoleKeys::APPLICANT]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $hist = $pdo->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_key = ?',
        );
        $hist->execute([$user['user_id'], RoleKeys::APPLICANT]);
        self::assertSame(1, (int) $hist->fetchColumn());

        self::assertSame($initial + 1, DatabaseTestCase::authVersion($user['user_id']));
    }
}
