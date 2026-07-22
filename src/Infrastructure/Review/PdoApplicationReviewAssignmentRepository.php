<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Review;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Review\ApplicationReviewAssignment;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Domain\Review\ApplicationReviewAssignmentStatus;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class PdoApplicationReviewAssignmentRepository implements ApplicationReviewAssignmentRepository
{
    private const COLUMNS = 'assignment_id, application_id, reviewer_user_id, assignment_status,
        claimed_at, released_at, completed_at, active_marker, row_version, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findActiveForApplication(int $applicationId): ?ApplicationReviewAssignment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM application_review_assignments
             WHERE application_id = :application_id AND active_marker = 1',
        );
        $stmt->execute(['application_id' => $applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function lockActiveForApplication(int $applicationId): ?ApplicationReviewAssignment
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM application_review_assignments
             WHERE application_id = :application_id AND active_marker = 1
             FOR UPDATE',
        );
        $stmt->execute(['application_id' => $applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function claim(
        int $applicationId,
        int $reviewerUserId,
        DateTimeImmutable $now,
    ): ApplicationReviewAssignment {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $existing = $this->lockActiveForApplication($applicationId);
        if ($existing !== null) {
            if ($existing->reviewerUserId === $reviewerUserId) {
                return $existing;
            }

            throw new ConflictException('Application is already claimed by another reviewer.');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO application_review_assignments (
                    application_id, reviewer_user_id, assignment_status, claimed_at,
                    released_at, completed_at, active_marker, row_version, created_at, updated_at
                ) VALUES (
                    :application_id, :reviewer_user_id, :assignment_status, :claimed_at,
                    NULL, NULL, 1, 1, :created_at, :updated_at
                )',
            );
            $stmt->execute([
                'application_id' => $applicationId,
                'reviewer_user_id' => $reviewerUserId,
                'assignment_status' => ApplicationReviewAssignmentStatus::ACTIVE,
                'claimed_at' => $nowStr,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateActiveClaim($e)) {
                throw new ConflictException('Application was claimed concurrently by another reviewer.');
            }

            throw $e;
        }

        $created = $this->findActiveForApplication($applicationId);
        if ($created === null) {
            throw new \RuntimeException('Failed to load claimed application review assignment.');
        }

        return $created;
    }

    public function release(
        int $assignmentId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            "UPDATE application_review_assignments
             SET assignment_status = 'released',
                 active_marker = NULL,
                 released_at = :released_at,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE assignment_id = :assignment_id
               AND active_marker = 1
               AND assignment_status = 'active'
               AND row_version = :row_version",
        );
        $stmt->execute([
            'released_at' => $nowStr,
            'updated_at' => $nowStr,
            'assignment_id' => $assignmentId,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function complete(
        int $assignmentId,
        int $expectedRowVersion,
        DateTimeImmutable $now,
    ): bool {
        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            "UPDATE application_review_assignments
             SET assignment_status = 'completed',
                 active_marker = NULL,
                 completed_at = :completed_at,
                 row_version = row_version + 1,
                 updated_at = :updated_at
             WHERE assignment_id = :assignment_id
               AND active_marker = 1
               AND assignment_status = 'active'
               AND row_version = :row_version",
        );
        $stmt->execute([
            'completed_at' => $nowStr,
            'updated_at' => $nowStr,
            'assignment_id' => $assignmentId,
            'row_version' => $expectedRowVersion,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ApplicationReviewAssignment
    {
        $utc = new DateTimeZone('UTC');

        return new ApplicationReviewAssignment(
            assignmentId: (int) $row['assignment_id'],
            applicationId: (int) $row['application_id'],
            reviewerUserId: (int) $row['reviewer_user_id'],
            assignmentStatus: (string) $row['assignment_status'],
            claimedAt: new DateTimeImmutable((string) $row['claimed_at'], $utc),
            releasedAt: $row['released_at'] === null ? null : new DateTimeImmutable((string) $row['released_at'], $utc),
            completedAt: $row['completed_at'] === null ? null : new DateTimeImmutable((string) $row['completed_at'], $utc),
            activeMarker: $row['active_marker'] === null ? null : (int) $row['active_marker'],
            rowVersion: (int) $row['row_version'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }

    private function isDuplicateActiveClaim(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'uq_application_review_assignments_active')
            || ($e->errorInfo[1] ?? null) === 1062;
    }
}
