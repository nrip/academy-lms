<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateInterval;
use DateTimeImmutable;

/**
 * Stuck-scan SLA policy (addendum §4.6). Values configurable until scanner selected.
 */
final class StuckScanPolicy
{
    public function __construct(
        private readonly int $slaSeconds = 900,
        private readonly int $maxAttempts = 5,
        private readonly int $backoffBaseSeconds = 60,
        private readonly int $backoffCapSeconds = 900,
    ) {
    }

    public function isStuck(DateTimeImmutable $now, ?DateTimeImmutable $scanQueuedAt, string $scanStatus): bool
    {
        if ($scanStatus !== DocumentScanStatus::PENDING || $scanQueuedAt === null) {
            return false;
        }

        return $scanQueuedAt->getTimestamp() + $this->slaSeconds <= $now->getTimestamp();
    }

    public function retriesExhausted(int $scanAttemptCount): bool
    {
        return $scanAttemptCount >= $this->maxAttempts;
    }

    public function nextRetryAt(DateTimeImmutable $now, int $attemptCount): DateTimeImmutable
    {
        $delay = min(
            $this->backoffCapSeconds,
            $this->backoffBaseSeconds * (2 ** max(0, $attemptCount - 1)),
        );

        return $now->add(new DateInterval('PT' . $delay . 'S'));
    }

    public function slaSeconds(): int
    {
        return $this->slaSeconds;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
