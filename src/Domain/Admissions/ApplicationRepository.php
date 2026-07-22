<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use DateTimeImmutable;

/**
 * Deliberately has no general updateStatus()/save(). Status changes go through
 * applyTransition() only, called from application services after ApplicationStateMachine.
 */
interface ApplicationRepository
{
    public function findById(int $applicationId): ?Application;

    public function findByIdForUpdate(int $applicationId): ?Application;

    public function findByUserAndBatch(int $userId, int $batchId): ?Application;

    public function insertDraft(
        int $userId,
        int $courseVersionId,
        int $batchId,
        DateTimeImmutable $now,
    ): Application;

    public function updateDeclaration(
        int $applicationId,
        string $declarationVersion,
        DateTimeImmutable $acceptedAt,
        int $expectedStateVersion,
        DateTimeImmutable $now,
    ): bool;

    /**
     * CAS status transition. Returns false on state_version / from_status mismatch.
     */
    public function applyTransition(
        int $applicationId,
        string $fromStatus,
        string $toStatus,
        ?DateTimeImmutable $submittedAt,
        int $expectedStateVersion,
        DateTimeImmutable $now,
    ): bool;
}
