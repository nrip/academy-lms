<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Database;

use PDO;
use PDOException;
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

    /**
     * Retry on InnoDB deadlock / lock-wait timeout (WP-05/WP-06 concurrency paths).
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function runWithDeadlockRetry(callable $callback, int $maxAttempts = 5): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            ++$attempts;
            try {
                return $this->run($callback);
            } catch (PDOException $exception) {
                $lastException = $exception;
                $sqlState = $exception->errorInfo[0] ?? '';
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                $isDeadlock = $sqlState === '40001' || $driverCode === 1213 || $driverCode === 1205;
                if (!$isDeadlock || $attempts >= $maxAttempts) {
                    throw $exception;
                }
                usleep(random_int(5_000, 40_000));
            }
        }

        throw $lastException ?? new PDOException('Deadlock retry exhausted.');
    }
}
