<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Courses\BatchRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoBatchRepository implements BatchRepository
{
    private const COLUMNS = 'batch_id, course_version_id, batch_code, name, starts_at, ends_at,
        applications_open_at, applications_close_at, min_capacity, max_capacity, delivery_mode,
        venue_or_online_details, timezone, fee_override, currency, status, access_expires_at,
        created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $batchId): ?Batch
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM batches WHERE batch_id = :id');
        $stmt->execute(['id' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByIdForUpdate(int $batchId): ?Batch
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM batches WHERE batch_id = :id FOR UPDATE');
        $stmt->execute(['id' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function listByCourseVersionId(int $courseVersionId): array
    {
        return $this->listByCourseVersionIds([$courseVersionId]);
    }

    /**
     * @param list<int> $courseVersionIds
     * @return list<Batch>
     */
    public function listByCourseVersionIds(array $courseVersionIds): array
    {
        if ($courseVersionIds === []) {
            return [];
        }

        $pdo = $this->connections->connection();
        $placeholders = implode(',', array_fill(0, count($courseVersionIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM batches
             WHERE course_version_id IN ($placeholders)
             ORDER BY applications_open_at ASC, batch_id ASC",
        );
        $stmt->execute(array_map(static fn (int $id): int => $id, $courseVersionIds));

        $batches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $batches[] = $this->mapRow($row);
        }

        return $batches;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Batch
    {
        $utc = new DateTimeZone('UTC');

        return new Batch(
            batchId: (int) $row['batch_id'],
            courseVersionId: (int) $row['course_version_id'],
            batchCode: (string) $row['batch_code'],
            name: (string) $row['name'],
            startsAt: new DateTimeImmutable((string) $row['starts_at'], $utc),
            endsAt: new DateTimeImmutable((string) $row['ends_at'], $utc),
            applicationsOpenAt: new DateTimeImmutable((string) $row['applications_open_at'], $utc),
            applicationsCloseAt: new DateTimeImmutable((string) $row['applications_close_at'], $utc),
            minCapacity: (int) $row['min_capacity'],
            maxCapacity: (int) $row['max_capacity'],
            deliveryMode: (string) $row['delivery_mode'],
            venueOrOnlineDetails: (string) $row['venue_or_online_details'],
            timezone: (string) $row['timezone'],
            feeOverride: $row['fee_override'] === null ? null : (string) $row['fee_override'],
            currency: (string) $row['currency'],
            status: (string) $row['status'],
            accessExpiresAt: $row['access_expires_at'] === null ? null : new DateTimeImmutable((string) $row['access_expires_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
