<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

interface CourseVersionStatusHistoryRepository
{
    public function append(
        int $versionId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        ?string $reason,
        DateTimeImmutable $at,
    ): void;
}
