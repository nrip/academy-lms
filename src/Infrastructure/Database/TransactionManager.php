<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Database;

use PDO;
use Throwable;

final class TransactionManager
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $pdo = $this->connections->connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
