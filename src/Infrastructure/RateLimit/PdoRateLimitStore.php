<?php

declare(strict_types=1);

namespace Academy\Infrastructure\RateLimit;

use Academy\Domain\Security\RateLimitStore;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

/**
 * Atomic rate-limit increment using MySQL LAST_INSERT_ID / ROW_COUNT pattern.
 *
 * No statement may execute on the same connection between the upsert and the
 * decision-count retrieval.
 *
 * Decision count:
 * - ROW_COUNT() = 1 → INSERT path → hit_count is 1
 * - otherwise → UPDATE path → LAST_INSERT_ID() holds the post-update hit_count
 *   (MySQL ON DUPLICATE KEY UPDATE returns ROW_COUNT 2 when a row is updated)
 *
 * The unconditional `updated_at = ?` assignment is load-bearing: without it, a
 * no-op-looking UPDATE can yield ROW_COUNT() = 0 and break the decision branch.
 */
final class PdoRateLimitStore implements RateLimitStore
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function incrementAndGetCount(
        string $bucketKey,
        string $policyKey,
        \DateTimeImmutable $windowStartsAt,
        \DateTimeImmutable $windowEndsAt,
    ): int {
        $pdo = $this->connections->connection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $startStr = $windowStartsAt->format('Y-m-d H:i:s.u');
        $endStr = $windowEndsAt->format('Y-m-d H:i:s.u');

        $upsert = $pdo->prepare(
            'INSERT INTO rate_limit_buckets (
                bucket_key, policy_key, hit_count, window_starts_at, window_ends_at, created_at, updated_at
            ) VALUES (
                ?, ?, 1, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                hit_count = LAST_INSERT_ID(IF(window_ends_at > ?, hit_count + 1, 1)),
                window_starts_at = IF(window_ends_at > ?, window_starts_at, VALUES(window_starts_at)),
                window_ends_at = IF(window_ends_at > ?, window_ends_at, VALUES(window_ends_at)),
                policy_key = VALUES(policy_key),
                updated_at = ?', // load-bearing for ROW_COUNT(): forces UPDATE path to report 2, not 0
        );

        $upsert->execute([
            $bucketKey,
            $policyKey,
            $startStr,
            $endStr,
            $nowStr,
            $nowStr,
            $nowStr,
            $nowStr,
            $nowStr,
            $nowStr,
        ]);

        // Immediate decision retrieval — nothing else on this connection between upsert and here.
        $decision = $pdo->query('SELECT IF(ROW_COUNT() = 1, 1, LAST_INSERT_ID()) AS hit_count');
        if ($decision === false) {
            throw new \RuntimeException('Failed to read rate-limit decision count.');
        }
        $countRow = $decision->fetch(PDO::FETCH_ASSOC);
        if ($countRow === false) {
            throw new \RuntimeException('Empty rate-limit decision count.');
        }

        return (int) $countRow['hit_count'];
    }

    public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'DELETE FROM rate_limit_buckets WHERE window_ends_at < ? LIMIT ' . (int) $limit,
        );
        $stmt->execute([$now->format('Y-m-d H:i:s.u')]);

        return $stmt->rowCount();
    }
}
