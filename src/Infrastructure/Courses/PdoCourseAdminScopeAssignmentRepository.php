<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\CourseAdminScopeAssignment;
use Academy\Domain\Courses\CourseAdminScopeAssignmentRepository;
use Academy\Domain\Courses\CourseAdminScopeType;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoCourseAdminScopeAssignmentRepository implements CourseAdminScopeAssignmentRepository
{
    private const COLUMNS = 'scope_assignment_id, admin_user_id, scope_type, course_id, course_version_id,
        include_future_versions, effective_from, effective_to, revoked_at, revoked_reason,
        created_by_user_id, revoked_by_user_id, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listActiveForAdmin(int $adminUserId, DateTimeImmutable $at): array
    {
        $pdo = $this->connections->connection();
        $atUtc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM course_admin_scope_assignments
             WHERE admin_user_id = :admin_user_id
               AND revoked_at IS NULL
               AND effective_from <= :at
               AND (effective_to IS NULL OR effective_to >= :at2)
             ORDER BY scope_assignment_id ASC',
        );
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'at' => $atUtc,
            'at2' => $atUtc,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    public function insertCourseScope(
        int $adminUserId,
        int $courseId,
        bool $includeFutureVersions,
        DateTimeImmutable $effectiveFrom,
        int $createdByUserId,
    ): int {
        CourseAdminScopeType::assertValid(CourseAdminScopeType::COURSE);
        $pdo = $this->connections->connection();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO course_admin_scope_assignments (
                admin_user_id, scope_type, course_id, course_version_id, include_future_versions,
                effective_from, effective_to, revoked_at, revoked_reason,
                created_by_user_id, revoked_by_user_id, created_at, updated_at
             ) VALUES (
                :admin_user_id, :scope_type, :course_id, NULL, :include_future_versions,
                :effective_from, NULL, NULL, NULL,
                :created_by_user_id, NULL, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'scope_type' => CourseAdminScopeType::COURSE,
            'course_id' => $courseId,
            'include_future_versions' => $includeFutureVersions ? 1 : 0,
            'effective_from' => $effectiveFrom->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'created_by_user_id' => $createdByUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function insertCourseVersionScope(
        int $adminUserId,
        int $courseVersionId,
        DateTimeImmutable $effectiveFrom,
        int $createdByUserId,
    ): int {
        $pdo = $this->connections->connection();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'INSERT INTO course_admin_scope_assignments (
                admin_user_id, scope_type, course_id, course_version_id, include_future_versions,
                effective_from, effective_to, revoked_at, revoked_reason,
                created_by_user_id, revoked_by_user_id, created_at, updated_at
             ) VALUES (
                :admin_user_id, :scope_type, NULL, :course_version_id, 0,
                :effective_from, NULL, NULL, NULL,
                :created_by_user_id, NULL, :created_at, :updated_at
             )',
        );
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'scope_type' => CourseAdminScopeType::COURSE_VERSION,
            'course_version_id' => $courseVersionId,
            'effective_from' => $effectiveFrom->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'created_by_user_id' => $createdByUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function findActiveCourseScope(int $adminUserId, int $courseId, DateTimeImmutable $at): ?CourseAdminScopeAssignment
    {
        foreach ($this->listActiveForAdmin($adminUserId, $at) as $assignment) {
            if ($assignment->scopeType === CourseAdminScopeType::COURSE
                && $assignment->courseId === $courseId
            ) {
                return $assignment;
            }
        }

        return null;
    }

    public function revoke(int $scopeAssignmentId, int $revokedByUserId, string $reason, DateTimeImmutable $revokedAt): void
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE course_admin_scope_assignments
             SET revoked_at = :revoked_at, revoked_by_user_id = :revoked_by, revoked_reason = :reason,
                 updated_at = :updated_at
             WHERE scope_assignment_id = :id AND revoked_at IS NULL',
        );
        $stmt->execute([
            'revoked_at' => $revokedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'revoked_by' => $revokedByUserId,
            'reason' => $reason,
            'updated_at' => $revokedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'id' => $scopeAssignmentId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): CourseAdminScopeAssignment
    {
        $utc = new DateTimeZone('UTC');

        return new CourseAdminScopeAssignment(
            scopeAssignmentId: (int) $row['scope_assignment_id'],
            adminUserId: (int) $row['admin_user_id'],
            scopeType: (string) $row['scope_type'],
            courseId: $row['course_id'] === null ? null : (int) $row['course_id'],
            courseVersionId: $row['course_version_id'] === null ? null : (int) $row['course_version_id'],
            includeFutureVersions: (int) $row['include_future_versions'] === 1,
            effectiveFrom: new DateTimeImmutable((string) $row['effective_from'], $utc),
            effectiveTo: $row['effective_to'] === null ? null : new DateTimeImmutable((string) $row['effective_to'], $utc),
            revokedAt: $row['revoked_at'] === null ? null : new DateTimeImmutable((string) $row['revoked_at'], $utc),
            revokedReason: $row['revoked_reason'] === null ? null : (string) $row['revoked_reason'],
            createdByUserId: (int) $row['created_by_user_id'],
            revokedByUserId: $row['revoked_by_user_id'] === null ? null : (int) $row['revoked_by_user_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
        );
    }
}
