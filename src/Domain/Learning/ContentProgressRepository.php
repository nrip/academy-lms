<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface ContentProgressRepository
{
    public function findByEnrolmentAndContent(int $enrolmentId, int $contentId): ?ContentProgress;

    /**
     * @return list<ContentProgress>
     */
    public function listByEnrolmentId(int $enrolmentId): array;

    /**
     * Upserts access timestamps; creates not_started→in_progress on first access.
     */
    public function recordAccess(int $enrolmentId, int $contentId, DateTimeImmutable $at): ContentProgress;

    /**
     * Marks completed with optimistic concurrency. Returns false on row_version mismatch.
     */
    public function markCompleted(
        int $enrolmentId,
        int $contentId,
        string $completionSource,
        DateTimeImmutable $at,
        ?int $expectedRowVersion = null,
    ): ContentProgress;
}
