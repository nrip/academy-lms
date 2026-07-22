<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ConflictException;
use DateTimeImmutable;

interface LearnerQualificationRepository
{
    /**
     * @return list<LearnerQualification>
     */
    public function listByProfileId(int $profileId): array;

    public function findById(int $qualificationId): ?LearnerQualification;

    /**
     * @param array<string, scalar|null> $fields
     * @return int the new learner_qualification_id
     */
    public function insert(int $profileId, array $fields, int $displayOrder, DateTimeImmutable $now): int;

    /**
     * @param array<string, scalar|null> $fields
     * @return int the new row_version
     * @throws ConflictException when the expected version no longer matches
     */
    public function updateWithVersion(int $qualificationId, int $expectedVersion, array $fields, DateTimeImmutable $now): int;

    /**
     * @throws ConflictException when the expected version no longer matches
     */
    public function deleteWithVersion(int $qualificationId, int $expectedVersion): void;

    public function nextDisplayOrder(int $profileId): int;

    public function countByProfileId(int $profileId): int;
}
