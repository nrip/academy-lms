<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface EnrolmentRepository
{
    public function findById(int $enrolmentId): ?Enrolment;

    public function findByApplicationId(int $applicationId): ?Enrolment;

    public function findByApplicationIdForUpdate(int $applicationId): ?Enrolment;

    public function findByPaymentId(int $paymentId): ?Enrolment;

    public function insertCreated(
        string $publicReference,
        int $applicationId,
        int $userId,
        int $courseId,
        int $courseVersionId,
        int $batchId,
        int $paymentId,
        string $lifecycleStatus,
        ?string $academicStatus,
        DateTimeImmutable $admittedAt,
        ?DateTimeImmutable $activatedAt,
        ?DateTimeImmutable $accessExpiresAt,
        DateTimeImmutable $now,
    ): Enrolment;

    /**
     * Occupying seats: scheduled/active enrolments + admitted applications without enrolment.
     */
    public function countOccupiedSeatsForBatch(int $batchId): int;

    public function applyLifecycleTransition(
        int $enrolmentId,
        string $fromStatus,
        string $toStatus,
        int $expectedRowVersion,
        DateTimeImmutable $now,
        ?DateTimeImmutable $activatedAt = null,
    ): bool;
}
