<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;

final class Wp01b2aMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
    }

    public function testMigrateCreatesTables(): void
    {
        DatabaseTestCase::migrate();
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'verification_tokens'));
        self::assertTrue($this->tableExists($pdo, 'verification_challenges'));
        self::assertTrue($this->tableExists($pdo, 'token_confirmation_contexts'));
    }

    public function testRollbackThenReapply(): void
    {
        DatabaseTestCase::migrate();
        $pdo = DatabaseTestCase::pdo();
        self::assertTrue($this->tableExists($pdo, 'verification_tokens'));

        $this->runPhinxRollback('20260720000003');

        self::assertFalse($this->tableExists($pdo, 'verification_tokens'));
        self::assertFalse($this->tableExists($pdo, 'verification_challenges'));
        self::assertFalse($this->tableExists($pdo, 'token_confirmation_contexts'));
        // Prior foundation tables remain.
        self::assertTrue($this->tableExists($pdo, 'users'));
        self::assertTrue($this->tableExists($pdo, 'outbox_messages'));

        $this->runPhinxMigrate();
        self::assertTrue($this->tableExists($pdo, 'verification_tokens'));
        self::assertTrue($this->tableExists($pdo, 'verification_challenges'));
        self::assertTrue($this->tableExists($pdo, 'token_confirmation_contexts'));
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
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        self::assertSame(0, $status, trim($stdout . "\n" . $stderr));
    }

    private function runPhinxRollback(string $targetVersion): void
    {
        $root = dirname(__DIR__, 3);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phinx', 'rollback', '-e', 'testing', '-t', $targetVersion],
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
