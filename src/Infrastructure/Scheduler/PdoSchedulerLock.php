<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Scheduler;

use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoSchedulerLock
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function acquire(string $lockName, string $lockedBy, int $ttlSeconds): bool
    {
        $pdo = $this->connections->connection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->modify('+' . $ttlSeconds . ' seconds');
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $untilStr = $until->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO scheduler_locks (lock_name, locked_until, locked_by, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                locked_by = IF(locked_until < ?, VALUES(locked_by), locked_by),
                locked_until = IF(locked_until < ?, VALUES(locked_until), locked_until),
                updated_at = IF(locked_until < ? OR locked_by = ?, ?, updated_at)',
        );
        $stmt->execute([
            $lockName,
            $untilStr,
            $lockedBy,
            $nowStr,
            $nowStr,
            $nowStr,
            $nowStr,
            $lockedBy,
            $nowStr,
        ]);

        $check = $pdo->prepare(
            'SELECT locked_by, locked_until FROM scheduler_locks WHERE lock_name = ? LIMIT 1',
        );
        $check->execute([$lockName]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        return (string) $row['locked_by'] === $lockedBy
            && new \DateTimeImmutable((string) $row['locked_until'], new \DateTimeZone('UTC')) > $now;
    }

    public function renew(string $lockName, string $lockedBy, int $ttlSeconds): bool
    {
        $pdo = $this->connections->connection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->modify('+' . $ttlSeconds . ' seconds');
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'UPDATE scheduler_locks
             SET locked_until = ?, updated_at = ?
             WHERE lock_name = ? AND locked_by = ? AND locked_until > ?',
        );
        $stmt->execute([
            $until->format('Y-m-d H:i:s.u'),
            $nowStr,
            $lockName,
            $lockedBy,
            $nowStr,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function release(string $lockName, string $lockedBy): void
    {
        $pdo = $this->connections->connection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $past = $now->modify('-1 second');
        $stmt = $pdo->prepare(
            'UPDATE scheduler_locks
             SET locked_until = ?, updated_at = ?
             WHERE lock_name = ? AND locked_by = ?',
        );
        $stmt->execute([
            $past->format('Y-m-d H:i:s.u'),
            $now->format('Y-m-d H:i:s.u'),
            $lockName,
            $lockedBy,
        ]);
    }
}
