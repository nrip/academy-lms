<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Admissions;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationNumberGenerator;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoApplicationRepository implements ApplicationRepository
{
    private const COLUMNS = 'application_id, application_number, user_id, course_version_id, batch_id, status,
        state_version, submitted_at, declaration_accepted_version, declaration_accepted_at, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly ApplicationNumberGenerator $numbers = new ApplicationNumberGenerator(),
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

    public function findByIdForUpdate(int $applicationId): ?Application
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM applications WHERE application_id = :id FOR UPDATE',
        );
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
        $applicationNumber = $this->numbers->generate();

        $stmt = $pdo->prepare(
            'INSERT INTO applications (
                application_number, user_id, course_version_id, batch_id, status, state_version,
                submitted_at, declaration_accepted_version, declaration_accepted_at, created_at, updated_at
            ) VALUES (
                :application_number, :user_id, :course_version_id, :batch_id, :status, 1,
                NULL, NULL, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'application_number' => $applicationNumber,
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
            applicationNumber: $applicationNumber,
            userId: $userId,
            courseVersionId: $courseVersionId,
            batchId: $batchId,
            status: ApplicationStatus::DRAFT,
            stateVersion: 1,
            submittedAt: null,
            declarationAcceptedVersion: null,
            declarationAcceptedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function updateDeclaration(
        int $applicationId,
        string $declarationVersion,
        DateTimeImmutable $acceptedAt,
        int $expectedStateVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE applications
             SET declaration_accepted_version = :version,
                 declaration_accepted_at = :accepted_at,
                 state_version = state_version + 1,
                 updated_at = :updated_at
             WHERE application_id = :id
               AND status = :draft
               AND state_version = :state_version',
        );
        $stmt->execute([
            'version' => $declarationVersion,
            'accepted_at' => $acceptedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'id' => $applicationId,
            'draft' => ApplicationStatus::DRAFT,
            'state_version' => $expectedStateVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function applyTransition(
        int $applicationId,
        string $fromStatus,
        string $toStatus,
        ?DateTimeImmutable $submittedAt,
        int $expectedStateVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE applications
             SET status = :to_status,
                 submitted_at = COALESCE(:submitted_at, submitted_at),
                 state_version = state_version + 1,
                 updated_at = :updated_at
             WHERE application_id = :id
               AND status = :from_status
               AND state_version = :state_version',
        );
        $stmt->execute([
            'to_status' => $toStatus,
            'submitted_at' => $submittedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'id' => $applicationId,
            'from_status' => $fromStatus,
            'state_version' => $expectedStateVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Application
    {
        $utc = new DateTimeZone('UTC');

        return new Application(
            applicationId: (int) $row['application_id'],
            applicationNumber: (string) $row['application_number'],
            userId: (int) $row['user_id'],
            courseVersionId: (int) $row['course_version_id'],
            batchId: (int) $row['batch_id'],
            status: (string) $row['status'],
            stateVersion: (int) $row['state_version'],
            submittedAt: $row['submitted_at'] === null ? null : new DateTimeImmutable((string) $row['submitted_at'], $utc),
            declarationAcceptedVersion: $row['declaration_accepted_version'] === null
                ? null
                : (string) $row['declaration_accepted_version'],
            declarationAcceptedAt: $row['declaration_accepted_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['declaration_accepted_at'], $utc),
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
