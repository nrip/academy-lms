<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use DateTimeImmutable;

final class EnrolmentFactory
{
    public function initialLifecycle(DateTimeImmutable $batchStartsAt, DateTimeImmutable $now): string
    {
        return $batchStartsAt > $now
            ? EnrolmentLifecycleStatus::SCHEDULED
            : EnrolmentLifecycleStatus::ACTIVE;
    }

    public function initialAcademicStatus(string $lifecycleStatus): ?string
    {
        return $lifecycleStatus === EnrolmentLifecycleStatus::ACTIVE
            ? EnrolmentAcademicStatus::NOT_STARTED
            : null;
    }

    public function activatedAt(string $lifecycleStatus, DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $lifecycleStatus === EnrolmentLifecycleStatus::ACTIVE ? $now : null;
    }
}
