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

    /**
     * @param array{
     *   course_version_id: int,
     *   batch_code: string,
     *   name: string,
     *   starts_at: \DateTimeImmutable,
     *   ends_at: \DateTimeImmutable,
     *   applications_open_at: \DateTimeImmutable,
     *   applications_close_at: \DateTimeImmutable,
     *   min_capacity: int,
     *   max_capacity: int,
     *   delivery_mode: string,
     *   venue_or_online_details: string,
     *   timezone: string,
     *   fee_override: ?string,
     *   currency: string,
     *   status: string,
     *   access_expires_at: ?\DateTimeImmutable
     * } $data
     */
    public function insert(array $data): int;
}
