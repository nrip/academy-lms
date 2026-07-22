<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ConflictException;
use DateTimeImmutable;

interface LearnerProfileRepository
{
    /**
     * Creates the one-row stub profile owned by registration (B-2d adds columns later).
     */
    public function insertStub(int $userId, DateTimeImmutable $now): int;

    public function findByUserId(int $userId): ?LearnerProfile;

    public function findById(int $profileId): ?LearnerProfile;

    /**
     * Compare-and-swap on row_version for the personal section.
     *
     * @param array<string, scalar|null> $fields
     * @return int the new row_version
     * @throws ConflictException when the expected version no longer matches
     */
    public function updatePersonal(int $profileId, int $expectedVersion, array $fields, DateTimeImmutable $now): int;

    /**
     * Compare-and-swap on row_version for the professional section.
     *
     * @param array<string, scalar|null> $fields
     * @return int the new row_version
     * @throws ConflictException when the expected version no longer matches
     */
    public function updateProfessional(int $profileId, int $expectedVersion, array $fields, DateTimeImmutable $now): int;
}
