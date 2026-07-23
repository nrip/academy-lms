<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

use DateTimeImmutable;

interface DocumentSubmissionRepository
{
    public function findById(int $documentSubmissionId): ?DocumentSubmission;

    public function findCurrentForRequirement(int $applicationId, int $requirementId): ?DocumentSubmission;

    /**
     * @return list<DocumentSubmission>
     */
    public function listCurrentForApplication(int $applicationId): array;

    /**
     * @return list<DocumentSubmission>
     */
    public function listHistoryForRequirement(int $applicationId, int $requirementId): array;

    public function lockCurrentForUpdate(int $applicationId, int $requirementId): ?DocumentSubmission;

    /**
     * @return list<DocumentSubmission>
     */
    public function lockAllCurrentForApplication(int $applicationId): array;

    public function supersedeCurrent(
        int $documentSubmissionId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    public function insertCurrent(DocumentSubmissionWrite $write): DocumentSubmission;

    public function applyScanResult(
        int $documentSubmissionId,
        int $expectedRowVersion,
        string $businessStatus,
        string $scanStatus,
        DateTimeImmutable $now,
        string $leaseOwner,
        string $leaseToken,
    ): bool;

    /**
     * @return list<DocumentSubmission>
     */
    public function claimPendingScan(
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $limit = 10,
    ): array;

    /**
     * @return list<DocumentSubmission>
     */
    public function listStuckPending(DateTimeImmutable $now, int $slaSeconds, int $limit = 50): array;

    public function markScanRequeued(
        int $documentSubmissionId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Promote Uploaded+clean → Under Review without scan-lease fencing.
     */
    public function promoteUploadedToUnderReview(
        int $documentSubmissionId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool;

    public function applyReviewDecision(
        int $id,
        int $expectedRowVersion,
        string $toStatus,
        ?string $reasonCode,
        ?string $learnerVisibleMessage,
        int $reviewerUserId,
        DateTimeImmutable $now,
    ): bool;
}
