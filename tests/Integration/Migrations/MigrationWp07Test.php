<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationWp07Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testNotificationDeliveriesTableAndPermissionsExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'notification_deliveries'));

        foreach (['dashboard.view_own', 'notification.view', 'notification.retry'] as $key) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key = ?');
            $stmt->execute([$key]);
            self::assertSame(1, (int) $stmt->fetchColumn(), $key);
        }

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             INNER JOIN roles r ON r.role_id = rp.role_id
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE r.role_key = 'applicant' AND p.permission_key = 'dashboard.view_own'",
        );
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             INNER JOIN roles r ON r.role_id = rp.role_id
             INNER JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE r.role_key = 'super_admin' AND p.permission_key IN ('notification.view', 'notification.retry')",
        );
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testRollbackThenReapply(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'notification_deliveries'));

        $this->runPhinxRollback('20260723000008');

        self::assertFalse($this->tableExists($pdo, 'notification_deliveries'));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key = ?');
        $stmt->execute(['dashboard.view_own']);
        self::assertSame(0, (int) $stmt->fetchColumn());

        DatabaseTestCase::migrate();
        self::assertTrue($this->tableExists($pdo, 'notification_deliveries'));
        $stmt->execute(['dashboard.view_own']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    private function runPhinxRollback(string $targetVersion): void
    {
        $this->runPhinx(['rollback', '-e', 'testing', '-t', $targetVersion]);
    }

    /**
     * @param list<string> $args
     */
    private function runPhinx(array $args): void
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phinx', ...$args],
            $descriptors,
            $pipes,
            $root,
            $this->phinxEnv(),
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        self::assertSame(0, $status, trim($stdout . "\n" . $stderr));
    }

    /**
     * @return array<string, string>
     */
    private function phinxEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
