<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use DateTimeImmutable;

/**
 * Deliberately has no updateStatus()/save() method. Submit and every later
 * transition belongs to the future ApplicationStateMachine (out of scope here).
 */
interface ApplicationRepository
{
    public function findById(int $applicationId): ?Application;

    public function findByUserAndBatch(int $userId, int $batchId): ?Application;

    /**
     * Inserts a new Draft. Relies on the UNIQUE(user_id, batch_id) constraint
     * for idempotent-create races; callers should catch the resulting
     * unique-violation and re-read rather than relying solely on this method.
     */
    public function insertDraft(
        int $userId,
        int $courseVersionId,
        int $batchId,
        DateTimeImmutable $now,
    ): Application;
}
