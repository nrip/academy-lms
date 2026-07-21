<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

interface UserSecuritySnapshotRepository
{
    /**
     * @throws \Throwable on store failure — callers must map to 503 for bound sessions
     */
    public function findById(int $userId): ?UserSecuritySnapshot;
}
