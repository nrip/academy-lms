<?php

declare(strict_types=1);

namespace Academy\Domain\Security;

interface RateLimitStore
{
    /**
     * Atomically increments (or resets) the bucket and returns the decision count
     * for THIS statement on THIS connection. No intervening statements allowed.
     *
     * @return array{hit_count: int, window_ends_at: \DateTimeImmutable}
     */
    public function incrementAndGetCount(
        string $bucketKey,
        string $policyKey,
        \DateTimeImmutable $windowStartsAt,
        \DateTimeImmutable $windowEndsAt,
    ): array;

    /**
     * @return int Deleted row count
     */
    public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int;
}
