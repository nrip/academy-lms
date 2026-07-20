<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

final class DatabaseTestCase
{
    public static function available(): bool
    {
        try {
            self::pdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function pdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        $probe = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $probe->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        );
    }

    public static function connectionFactory(): ConnectionFactory
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        self::pdo();

        return new ConnectionFactory([
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        ]);
    }

    public static function migrate(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $configArray = require dirname(__DIR__, 2) . '/phinx.php';
        $config = new Config($configArray);
        $manager = new Manager($config, new StringInput(''), new NullOutput());
        $manager->migrate('testing');
    }

    public static function truncateWp01aTables(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['sessions', 'rate_limit_buckets', 'outbox_messages', 'scheduler_locks'] as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        // audit_log has delete trigger — disable triggers via temporary workaround:
        // drop trigger, truncate, recreate is heavy; instead insert-only tests use unique actions.
        // For cleanup we drop and recreate triggers around delete.
        $pdo->exec('DROP TRIGGER IF EXISTS trg_audit_log_forbid_delete');
        $pdo->exec('DELETE FROM audit_log');
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_audit_log_forbid_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'
SQL);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
