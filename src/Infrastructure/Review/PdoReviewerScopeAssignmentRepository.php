<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Review;

use Academy\Domain\Review\ReviewerScopeAssignment;
use Academy\Domain\Review\ReviewerScopeAssignmentRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoReviewerScopeAssignmentRepository implements ReviewerScopeAssignmentRepository
{
    private const COLUMNS = 'scope_assignment_id, reviewer_user_id, scope_type, course_id, course_version_id,
        batch_id, include_future_versions, effective_from, effective_to, revoked_at, revoked_reason,
        created_by_user_id, revoked_by_user_id, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listActiveForReviewer(int $reviewerUserId, DateTimeImmutable $at): array
    {
        $pdo = $this->connections->connection();
        $atStr = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM reviewer_scope_assignments
             WHERE reviewer_user_id = :reviewer_user_id
               AND revoked_at IS NULL
               AND effective_from <= :at_from
               AND (effective_to IS NULL OR effective_to >= :at_to)
             ORDER BY scope_assignment_id ASC',
        );
        $stmt->execute([
            'reviewer_user_id' => $reviewerUserId,
            'at_from' => $atStr,
            'at_to' => $atStr,
        ]);

        return array_values(array_map($this->mapRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ReviewerScopeAssignment
    {
        $utc = new DateTimeZone('UTC');

        return new ReviewerScopeAssignment(
            scopeAssignmentId: (int) $row['scope_assignment_id'],
            reviewerUserId: (int) $row['reviewer_user_id'],
            scopeType: (string) $row['scope_type'],
            courseId: $row['course_id'] === null ? null : (int) $row['course_id'],
            courseVersionId: $row['course_version_id'] === null ? null : (int) $row['course_version_id'],
            batchId: $row['batch_id'] === null ? null : (int) $row['batch_id'],
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
