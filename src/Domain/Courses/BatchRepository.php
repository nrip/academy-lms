<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

interface BatchRepository
{
    public function findById(int $batchId): ?Batch;

    public function findByIdForUpdate(int $batchId): ?Batch;

    /**
     * @return list<Batch>
     */
    public function listByCourseVersionId(int $courseVersionId): array;

    /**
     * @param list<int> $courseVersionIds
     * @return list<Batch>
     */
    public function listByCourseVersionIds(array $courseVersionIds): array;
}
