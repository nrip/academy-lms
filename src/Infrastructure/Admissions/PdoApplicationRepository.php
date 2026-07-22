<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Admissions;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoApplicationRepository implements ApplicationRepository
{
    private const COLUMNS = 'application_id, user_id, course_version_id, batch_id, status,
        submitted_at, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $applicationId): ?Application
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM applications WHERE application_id = :id');
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByUserAndBatch(int $userId, int $batchId): ?Application
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM applications WHERE user_id = :user_id AND batch_id = :batch_id',
        );
        $stmt->execute(['user_id' => $userId, 'batch_id' => $batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function insertDraft(
        int $userId,
        int $courseVersionId,
        int $batchId,
        DateTimeImmutable $now,
    ): Application {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO applications (
                user_id, course_version_id, batch_id, status, submitted_at, created_at, updated_at
            ) VALUES (
                :user_id, :course_version_id, :batch_id, :status, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_version_id' => $courseVersionId,
            'batch_id' => $batchId,
            'status' => ApplicationStatus::DRAFT,
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        $applicationId = (int) $pdo->lastInsertId();

        return new Application(
            applicationId: $applicationId,
            userId: $userId,
            courseVersionId: $courseVersionId,
            batchId: $batchId,
            status: ApplicationStatus::DRAFT,
            submittedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Application
    {
        $utc = new DateTimeZone('UTC');

        return new Application(
            applicationId: (int) $row['application_id'],
            userId: (int) $row['user_id'],
            courseVersionId: (int) $row['course_version_id'],
            batchId: (int) $row['batch_id'],
            status: (string) $row['status'],
            submittedAt: $row['submitted_at'] === null ? null : new DateTimeImmutable((string) $row['submitted_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
