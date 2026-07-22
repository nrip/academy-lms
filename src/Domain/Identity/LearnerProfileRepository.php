<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

interface LearnerProfileRepository
{
    /**
     * Creates the one-row stub profile owned by registration (B-2d adds columns later).
     */
    public function insertStub(int $userId, \DateTimeImmutable $now): int;
}
