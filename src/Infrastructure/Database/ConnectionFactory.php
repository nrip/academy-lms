<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    private ?PDO $pdo = null;

    /**
     * @param array{
     *   host: string,
     *   port: int,
     *   name: string,
     *   user: string,
     *   password: string,
     *   charset: string,
     *   options: array<int, mixed>
     * } $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
            $this->config['charset'],
        );

        try {
            $pdo = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['password'],
                $this->config['options'],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the database.', 0, $exception);
        }

        if (($this->config['options'][PDO::ATTR_PERSISTENT] ?? false) === true) {
            throw new RuntimeException('Persistent PDO connections are not permitted.');
        }

        $this->pdo = $pdo;

        return $this->pdo;
    }

    /**
     * @return array{
     *   errmode: mixed,
     *   persistent: mixed,
     *   emulate_prepares: mixed
     * }
     */
    public function connectionAttributes(): array
    {
        $pdo = $this->connection();

        return [
            'errmode' => $pdo->getAttribute(PDO::ATTR_ERRMODE),
            'persistent' => $pdo->getAttribute(PDO::ATTR_PERSISTENT),
            'emulate_prepares' => $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
        ];
    }
}
