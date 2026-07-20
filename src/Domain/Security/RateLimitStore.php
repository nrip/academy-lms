<?php

declare(strict_types=1);

namespace Academy\Domain\Security;

interface RateLimitStore
{
    /**
     * Atomically increments (or resets) the bucket and returns the decision count
     * for THIS statement on THIS connection. No intervening statements allowed.
     *
     * Retry-After is derived by the caller from the fixed window boundary already
     * computed for the request — this method does not re-read window_ends_at.
     */
    public function incrementAndGetCount(
        string $bucketKey,
        string $policyKey,
        \DateTimeImmutable $windowStartsAt,
        \DateTimeImmutable $windowEndsAt,
    ): int;

    /**
     * @return int Deleted row count
     */
    public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int;
}
