<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationWp06Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testTablesAndPermissionsExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        foreach (['payment_webhook_events', 'enrolments', 'enrolment_status_history'] as $table) {
            self::assertTrue($this->tableExists($pdo, $table), $table);
        }

        foreach ([
            'finance.payment.reconcile',
            'finance.payment.retry_reconciliation',
            'enrolment.view_own',
        ] as $key) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE permission_key = ?');
            $stmt->execute([$key]);
            self::assertSame(1, (int) $stmt->fetchColumn(), $key);
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM payments LIKE 'enrolment_id'");
        self::assertNotFalse($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testRollbackThenReapply(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'enrolments'));

        $this->runPhinxRollback('20260723000007');

        self::assertFalse($this->tableExists($pdo, 'enrolments'));
        self::assertFalse($this->tableExists($pdo, 'payment_webhook_events'));
        self::assertTrue($this->tableExists($pdo, 'payments'), 'WP-05 payments must remain.');

        DatabaseTestCase::migrate();
        self::assertTrue($this->tableExists($pdo, 'enrolments'));
        self::assertTrue($this->tableExists($pdo, 'payment_webhook_events'));
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
