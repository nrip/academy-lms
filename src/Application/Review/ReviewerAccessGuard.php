<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Review\ApplicationReviewAssignment;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Domain\Review\ReviewerScopePolicy;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;

/**
 * Shared reviewer authorization: permissions, finance SoD, WP01-E scope, claim ownership.
 * Super Admin has no scope bypass — permission and scope are both required.
 */
final class ReviewerAccessGuard
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationReviewAssignmentRepository $assignments,
        private readonly CourseVersionRepository $courseVersions,
        private readonly ReviewerScopePolicy $scopePolicy,
    ) {
    }

    public function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }

    public function requirePermission(AuthContext $auth, string $permissionKey): void
    {
        $this->assertFinanceDocumentSoD($auth);
        $this->authorization->require($auth, $permissionKey);
    }

    public function assertFinanceDocumentSoD(AuthContext $auth): void
    {
        if ($this->authorization->check($auth, 'finance.payment.view')
            && !$this->authorization->check($auth, 'document.metadata.view')
            && !$this->authorization->check($auth, 'document.signed_url.generate')
            && !$this->authorization->check($auth, 'document.view_own')
        ) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    public function loadApplicationForUpdate(int $applicationId): Application
    {
        $application = $this->applications->findByIdForUpdate($applicationId);
        if ($application === null) {
            throw new NotFoundException('Application not found.');
        }

        return $application;
    }

    public function loadApplication(int $applicationId): Application
    {
        $application = $this->applications->findById($applicationId);
        if ($application === null) {
            throw new NotFoundException('Application not found.');
        }

        return $application;
    }

    public function resolveCourseId(int $courseVersionId): int
    {
        $courseVersion = $this->courseVersions->findById($courseVersionId);
        if ($courseVersion === null) {
            throw new NotFoundException('Application not found.');
        }

        return $courseVersion->courseId;
    }

    public function assertApplicationInScope(
        AuthContext $auth,
        Application $application,
        DateTimeImmutable $at,
    ): void {
        $reviewerUserId = $this->requireUserId($auth);
        $courseId = $this->resolveCourseId($application->courseVersionId);

        if (!$this->scopePolicy->isInScope(
            $reviewerUserId,
            $courseId,
            $application->courseVersionId,
            $application->batchId,
            $at,
        )) {
            throw new AuthorizationException('Application is outside reviewer scope.');
        }
    }

    public function assertClaimableStatus(Application $application): void
    {
        if (!in_array($application->status, [
            ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::RESUBMISSION_REQUESTED,
        ], true)) {
            throw new DomainRuleException('Application is not available for claim.');
        }
    }

    public function assertActiveClaimOwnedBy(
        int $reviewerUserId,
        ?ApplicationReviewAssignment $assignment,
    ): ApplicationReviewAssignment {
        if ($assignment === null || !$assignment->isActive()) {
            throw new ConflictException('An active claim is required for this action.');
        }

        if ($assignment->reviewerUserId !== $reviewerUserId) {
            throw new ConflictException('Application is claimed by another reviewer.');
        }

        return $assignment;
    }

    public function lockActiveAssignment(int $applicationId): ?ApplicationReviewAssignment
    {
        return $this->assignments->lockActiveForApplication($applicationId);
    }
}
