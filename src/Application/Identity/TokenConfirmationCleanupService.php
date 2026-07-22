<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Domain\Identity\TokenConfirmationContextRepository;

/**
 * Physically deletes aged expired/consumed confirmation contexts (retention: 7 days).
 */
final class TokenConfirmationCleanupService
{
    public function __construct(
        private readonly TokenConfirmationContextRepository $contexts,
    ) {
    }

    /**
     * @return int Number of rows deleted
     */
    public function run(int $batchSize = 1000): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cutoff = $now->modify('-7 days');

        return $this->contexts->deleteExpiredOrConsumedBefore($cutoff, $batchSize);
    }
}
