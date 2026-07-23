<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Learning;

use Academy\Domain\Learning\Enrolment;
use Academy\Domain\Learning\EnrolmentAcademicStatus;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoEnrolmentRepository implements EnrolmentRepository
{
    private const COLUMNS = 'enrolment_id, public_reference, application_id, user_id, course_id, course_version_id,
        batch_id, payment_id, lifecycle_status, academic_status, admitted_at, activated_at, access_expires_at,
        row_version, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $enrolmentId): ?Enrolment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM enrolments WHERE enrolment_id = :id');
        $stmt->execute(['id' => $enrolmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByApplicationId(int $applicationId): ?Enrolment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM enrolments WHERE application_id = :application_id',
        );
        $stmt->execute(['application_id' => $applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByApplicationIdForUpdate(int $applicationId): ?Enrolment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM enrolments WHERE application_id = :application_id FOR UPDATE',
        );
        $stmt->execute(['application_id' => $applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByPaymentId(int $paymentId): ?Enrolment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM enrolments WHERE payment_id = :payment_id',
        );
        $stmt->execute(['payment_id' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function insertCreated(
        string $publicReference,
        int $applicationId,
        int $userId,
        int $courseId,
        int $courseVersionId,
        int $batchId,
        int $paymentId,
        string $lifecycleStatus,
        ?string $academicStatus,
        DateTimeImmutable $admittedAt,
        ?DateTimeImmutable $activatedAt,
        ?DateTimeImmutable $accessExpiresAt,
        DateTimeImmutable $now,
    ): Enrolment {
        EnrolmentLifecycleStatus::assertValid($lifecycleStatus);
        EnrolmentAcademicStatus::assertValid($academicStatus);

        $pdo = $this->connections->connection();
        $utc = new DateTimeZone('UTC');
        $nowStr = $now->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO enrolments (
                public_reference, application_id, user_id, course_id, course_version_id, batch_id, payment_id,
                lifecycle_status, academic_status, admitted_at, activated_at, access_expires_at,
                row_version, created_at, updated_at
            ) VALUES (
                :public_reference, :application_id, :user_id, :course_id, :course_version_id, :batch_id, :payment_id,
                :lifecycle_status, :academic_status, :admitted_at, :activated_at, :access_expires_at,
                1, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'public_reference' => $publicReference,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'course_id' => $courseId,
            'course_version_id' => $courseVersionId,
            'batch_id' => $batchId,
            'payment_id' => $paymentId,
            'lifecycle_status' => $lifecycleStatus,
            'academic_status' => $academicStatus,
            'admitted_at' => $admittedAt->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'activated_at' => $activatedAt?->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'access_expires_at' => $accessExpiresAt?->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
        ]);

        $enrolment = $this->findById((int) $pdo->lastInsertId());
        if ($enrolment === null) {
            throw new \RuntimeException('Failed to load inserted enrolment.');
        }

        return $enrolment;
    }

    public function countOccupiedSeatsForBatch(int $batchId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM enrolments
                 WHERE batch_id = :batch_id AND lifecycle_status IN (\'scheduled\', \'active\'))
                +
                (SELECT COUNT(*) FROM applications a
                 LEFT JOIN enrolments e ON e.application_id = a.application_id
                 WHERE a.batch_id = :batch_id2 AND a.status = \'admitted\' AND e.enrolment_id IS NULL)
            ) AS occupied',
        );
        $stmt->execute([
            'batch_id' => $batchId,
            'batch_id2' => $batchId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function applyLifecycleTransition(
        int $enrolmentId,
        string $fromStatus,
        string $toStatus,
        int $expectedRowVersion,
        DateTimeImmutable $now,
        ?DateTimeImmutable $activatedAt = null,
    ): bool {
        EnrolmentLifecycleStatus::assertValid($fromStatus);
        EnrolmentLifecycleStatus::assertValid($toStatus);

        $pdo = $this->connections->connection();
        $utc = new DateTimeZone('UTC');
        $nowStr = $now->setTimezone($utc)->format('Y-m-d H:i:s.u');

        $academic = null;
        if ($toStatus === EnrolmentLifecycleStatus::ACTIVE) {
            $academic = EnrolmentAcademicStatus::NOT_STARTED;
        }

        $stmt = $pdo->prepare(
            'UPDATE enrolments
             SET lifecycle_status = :to_status,
                 academic_status = COALESCE(:academic_status, academic_status),
                 activated_at = COALESCE(:activated_at, activated_at),
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE enrolment_id = :enrolment_id
               AND lifecycle_status = :from_status
               AND row_version = :row_version',
        );
        $stmt->execute([
            'to_status' => $toStatus,
            'academic_status' => $academic,
            'activated_at' => $activatedAt?->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'updated_at' => $nowStr,
            'enrolment_id' => $enrolmentId,
            'from_status' => $fromStatus,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Enrolment
    {
        $utc = new DateTimeZone('UTC');

        return new Enrolment(
            enrolmentId: (int) $row['enrolment_id'],
            publicReference: (string) $row['public_reference'],
            applicationId: (int) $row['application_id'],
            userId: (int) $row['user_id'],
            courseId: (int) $row['course_id'],
            courseVersionId: (int) $row['course_version_id'],
            batchId: (int) $row['batch_id'],
            paymentId: (int) $row['payment_id'],
            lifecycleStatus: (string) $row['lifecycle_status'],
            academicStatus: $row['academic_status'] === null ? null : (string) $row['academic_status'],
            admittedAt: new DateTimeImmutable((string) $row['admitted_at'], $utc),
            activatedAt: $row['activated_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['activated_at'], $utc),
            accessExpiresAt: $row['access_expires_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['access_expires_at'], $utc),
            rowVersion: (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
