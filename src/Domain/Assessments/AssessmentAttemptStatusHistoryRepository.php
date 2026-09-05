<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use DateTimeImmutable;

interface AssessmentAttemptStatusHistoryRepository
{
    public function append(
        int $attemptId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void;
}
