<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use Academy\Domain\Courses\CourseVersionRepository;
use DateTimeImmutable;

/**
 * Evaluates WP01-E reviewer object scope against an Application target.
 *
 * Course scope without include_future_versions matches only versions whose
 * published_at is on or before the assignment effective_from. Versions published
 * after assignment are excluded unless include_future_versions=1 (WP01-E
 * "no automatic future versions" posture).
 */
final class ReviewerScopePolicy
{
    public function __construct(
        private readonly ReviewerScopeAssignmentRepository $assignments,
        private readonly CourseVersionRepository $courseVersions,
    ) {
    }

    public function isInScope(
        int $reviewerUserId,
        int $courseId,
        int $courseVersionId,
        int $batchId,
        DateTimeImmutable $at,
    ): bool {
        $active = $this->assignments->listActiveForReviewer($reviewerUserId, $at);
        if ($active === []) {
            return false;
        }

        $applicationVersion = $this->courseVersions->findById($courseVersionId);
        if ($applicationVersion === null) {
            return false;
        }

        foreach ($active as $assignment) {
            if ($this->assignmentMatches(
                $assignment,
                $courseId,
                $courseVersionId,
                $batchId,
                $applicationVersion->publishedAt,
            )) {
                return true;
            }
        }

        return false;
    }

    private function assignmentMatches(
        ReviewerScopeAssignment $assignment,
        int $courseId,
        int $courseVersionId,
        int $batchId,
        ?DateTimeImmutable $applicationVersionPublishedAt,
    ): bool {
        return match ($assignment->scopeType) {
            ReviewerScopeType::BATCH => $assignment->batchId === $batchId,
            ReviewerScopeType::COURSE_VERSION => $assignment->courseVersionId === $courseVersionId,
            ReviewerScopeType::COURSE => $this->courseScopeMatches(
                $assignment,
                $courseId,
                $applicationVersionPublishedAt,
            ),
            default => false,
        };
    }

    private function courseScopeMatches(
        ReviewerScopeAssignment $assignment,
        int $courseId,
        ?DateTimeImmutable $applicationVersionPublishedAt,
    ): bool {
        if ($assignment->courseId !== $courseId) {
            return false;
        }

        if ($assignment->includeFutureVersions) {
            return true;
        }

        if ($applicationVersionPublishedAt === null) {
            return false;
        }

        return $applicationVersionPublishedAt <= $assignment->effectiveFrom;
    }
}
