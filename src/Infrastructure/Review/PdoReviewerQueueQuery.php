<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Review;

use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Review\ReviewerQueueFilter;
use Academy\Domain\Review\ReviewerQueueItem;
use Academy\Domain\Review\ReviewerQueueQuery;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoReviewerQueueQuery implements ReviewerQueueQuery
{
    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function listForReviewer(
        int $reviewerUserId,
        string $filter,
        DateTimeImmutable $now,
        int $limit = 50,
        int $offset = 0,
    ): array {
        ReviewerQueueFilter::assertValid($filter);

        $pdo = $this->connections->connection();
        $nowStr = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $recentCutoff = $now->modify('-7 days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $scopeSql = <<<'SQL'
EXISTS (
    SELECT 1
    FROM reviewer_scope_assignments rsa
    INNER JOIN course_versions cv_scope ON cv_scope.version_id = a.course_version_id
    WHERE rsa.reviewer_user_id = :scope_reviewer_user_id
      AND rsa.revoked_at IS NULL
      AND rsa.effective_from <= :scope_at_from
      AND (rsa.effective_to IS NULL OR rsa.effective_to >= :scope_at_to)
      AND (
          (rsa.scope_type = 'batch' AND rsa.batch_id = a.batch_id)
          OR (rsa.scope_type = 'course_version' AND rsa.course_version_id = a.course_version_id)
          OR (
              rsa.scope_type = 'course'
              AND rsa.course_id = cv_scope.course_id
              AND (
                  rsa.include_future_versions = 1
                  OR (
                      cv_scope.published_at IS NOT NULL
                      AND cv_scope.published_at <= rsa.effective_from
                  )
              )
          )
      )
)
SQL;

        $filterSql = match ($filter) {
            ReviewerQueueFilter::UNASSIGNED => "a.status IN ('under_review', 'resubmission_requested') AND ara.assignment_id IS NULL",
            ReviewerQueueFilter::ASSIGNED_TO_ME => 'ara.assignment_id IS NOT NULL AND ara.reviewer_user_id = :filter_reviewer_user_id',
            ReviewerQueueFilter::UNDER_REVIEW => "a.status = 'under_review'",
            ReviewerQueueFilter::RESUBMISSION_REQUESTED => "a.status = 'resubmission_requested'",
            ReviewerQueueFilter::READY_FOR_DECISION => $this->readyForDecisionSql(),
            ReviewerQueueFilter::RECENTLY_DECIDED => sprintf(
                "a.status IN ('%s', '%s') AND a.updated_at >= :recent_cutoff",
                ApplicationStatus::PAYMENT_PENDING,
                ApplicationStatus::REJECTED,
            ),
            default => "a.status = 'under_review'",
        };

        $sql = <<<SQL
SELECT
    a.application_id,
    a.application_number,
    cv.title AS course_title,
    b.name AS batch_label,
    a.submitted_at,
    a.status,
    ara.assignment_id,
    ara.reviewer_user_id AS assigned_reviewer_user_id,
    (
        SELECT CONCAT(
            COALESCE(SUM(CASE WHEN ds.status = 'approved' AND ds.scan_status = 'clean' THEN 1 ELSE 0 END), 0),
            '/',
            COALESCE(SUM(CASE WHEN cdr.mandatory_flag = 1 THEN 1 ELSE 0 END), 0)
        )
        FROM course_document_requirements cdr
        LEFT JOIN document_submissions ds
            ON ds.application_id = a.application_id
           AND ds.requirement_id = cdr.requirement_id
           AND ds.current_marker = 1
        WHERE cdr.course_version_id = a.course_version_id
          AND cdr.mandatory_flag = 1
    ) AS document_completeness_summary
FROM applications a
INNER JOIN course_versions cv ON cv.version_id = a.course_version_id
INNER JOIN batches b ON b.batch_id = a.batch_id
LEFT JOIN application_review_assignments ara
    ON ara.application_id = a.application_id
   AND ara.active_marker = 1
WHERE {$scopeSql}
  AND ({$filterSql})
ORDER BY a.submitted_at ASC, a.application_id ASC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue('scope_reviewer_user_id', $reviewerUserId, PDO::PARAM_INT);
        $stmt->bindValue('scope_at_from', $nowStr);
        $stmt->bindValue('scope_at_to', $nowStr);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        if ($filter === ReviewerQueueFilter::ASSIGNED_TO_ME) {
            $stmt->bindValue('filter_reviewer_user_id', $reviewerUserId, PDO::PARAM_INT);
        }
        if ($filter === ReviewerQueueFilter::RECENTLY_DECIDED) {
            $stmt->bindValue('recent_cutoff', $recentCutoff);
        }
        $stmt->execute();

        $utc = new DateTimeZone('UTC');
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $submittedAt = $row['submitted_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['submitted_at'], $utc);

            $items[] = new ReviewerQueueItem(
                applicationId: (int) $row['application_id'],
                applicationNumber: (string) $row['application_number'],
                courseTitle: (string) $row['course_title'],
                batchLabel: (string) $row['batch_label'],
                submittedAt: $submittedAt,
                status: (string) $row['status'],
                assignmentId: $row['assignment_id'] === null ? null : (int) $row['assignment_id'],
                assignedReviewerUserId: $row['assigned_reviewer_user_id'] === null
                    ? null
                    : (int) $row['assigned_reviewer_user_id'],
                slaAgeBand: $this->slaAgeBand($submittedAt, $now),
                documentCompletenessSummary: (string) ($row['document_completeness_summary'] ?? '0/0'),
            );
        }

        return $items;
    }

    private function readyForDecisionSql(): string
    {
        return <<<'SQL'
a.status = 'under_review'
AND NOT EXISTS (
    SELECT 1
    FROM course_document_requirements cdr
    LEFT JOIN document_submissions ds
        ON ds.application_id = a.application_id
       AND ds.requirement_id = cdr.requirement_id
       AND ds.current_marker = 1
    WHERE cdr.course_version_id = a.course_version_id
      AND cdr.mandatory = 1
      AND (
          ds.document_submission_id IS NULL
          OR ds.status <> 'approved'
          OR ds.scan_status <> 'clean'
      )
)
SQL;
    }

    private function slaAgeBand(?DateTimeImmutable $submittedAt, DateTimeImmutable $now): ?string
    {
        if ($submittedAt === null) {
            return null;
        }

        $hours = (int) floor(($now->getTimestamp() - $submittedAt->getTimestamp()) / 3600);
        if ($hours < 24) {
            return 'under_24h';
        }
        if ($hours < 72) {
            return '24_to_72h';
        }

        return 'over_72h';
    }
}
