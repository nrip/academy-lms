<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationWp01b2cTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
    }

    public function testMigrateAddsLockoutColumnsAndResetAuthorizationTable(): void
    {
        DatabaseTestCase::migrate();
        $pdo = DatabaseTestCase::pdo();

        self::assertTrue($this->columnExists($pdo, 'users', 'failed_login_window_started_at'));
        self::assertTrue($this->columnExists($pdo, 'users', 'last_failed_login_at'));
        self::assertTrue($this->columnExists($pdo, 'users', 'last_login_at'));
        self::assertTrue($this->tableExists($pdo, 'password_reset_authorizations'));
    }

    public function testRollbackThenReapply(): void
    {
        DatabaseTestCase::migrate();
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'password_reset_authorizations'));

        $this->runPhinxRollback('20260722000001');

        self::assertFalse($this->tableExists($pdo, 'password_reset_authorizations'));
        self::assertFalse($this->columnExists($pdo, 'users', 'failed_login_window_started_at'));
        self::assertTrue($this->tableExists($pdo, 'users'));
        self::assertTrue($this->tableExists($pdo, 'learner_profiles'));

        $this->runPhinxMigrate();
        self::assertTrue($this->tableExists($pdo, 'password_reset_authorizations'));
        self::assertTrue($this->columnExists($pdo, 'users', 'last_login_at'));
    }

    private function runPhinxMigrate(): void
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phinx', 'migrate', '-e', 'testing'],
            $descriptors,
            $pipes,
            $root,
            $this->phinxEnv(),
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc));
    }

    private function runPhinxRollback(string $target): void
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phinx', 'rollback', '-e', 'testing', '-t', $target],
            $descriptors,
            $pipes,
            $root,
            $this->phinxEnv(),
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc));
    }

    /**
     * @return array<string, string>
     */
    private function phinxEnv(): array
    {
        $env = $_ENV + $_SERVER;
        $env['APP_ENV'] = 'testing';

        return array_filter(
            $env,
            static fn (mixed $value): bool => is_string($value),
        );
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() === 1;
    }
}
