<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

interface LearnerEnrolmentGoalRepository
{
    public function findForEnrolment(int $enrolmentId): ?LearnerEnrolmentGoal;

    public function upsert(int $enrolmentId, int $userId, string $label, string $targetDate, DateTimeImmutable $at): int;

    public function deleteForEnrolment(int $enrolmentId, int $userId): bool;
}
