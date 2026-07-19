<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Database;

use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testCreatesNonPersistentUtf8ConnectionWhenDatabaseAvailable(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        try {
            $probe = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $probe->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\Throwable $exception) {
            self::markTestSkipped('MySQL is not available for integration tests: ' . $exception->getMessage());
        }

        $factory = new ConnectionFactory([
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

        $attributes = $factory->connectionAttributes();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $attributes['errmode']);
        self::assertFalse((bool) $attributes['persistent']);
        self::assertFalse((bool) $attributes['emulate_prepares']);
    }
}
