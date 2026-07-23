<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

/**
 * Bounded backoff for transactional notification retries.
 */
final class NotificationRetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $backoffBaseSeconds = 30,
        private readonly int $backoffCapSeconds = 3600,
    ) {
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function shouldDeadLetter(int $attemptCount, string $failureCategory): bool
    {
        if (!NotificationFailureCategory::isRetryable($failureCategory)) {
            return true;
        }

        return $attemptCount >= $this->maxAttempts;
    }

    public function backoffSeconds(int $attemptCount): int
    {
        $exponent = max(0, $attemptCount - 1);
        $seconds = $this->backoffBaseSeconds * (2 ** $exponent);

        return min($seconds, $this->backoffCapSeconds);
    }
}
