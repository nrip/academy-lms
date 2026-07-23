<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Domain\Review\ReviewerQueueFilter;
use Academy\Domain\Review\ReviewerQueueQuery;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;
use DateTimeZone;

final class ReviewerApplicationQueryService
{
    public function __construct(
        private readonly ReviewerAccessGuard $access,
        private readonly ReviewerQueueQuery $queueQuery,
        private readonly ApplicationReviewAssignmentRepository $assignments,
        private readonly CourseVersionRepository $courseVersions,
        private readonly BatchRepository $batches,
        private readonly CourseDocumentRequirementRepository $requirements,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly LearnerProfileRepository $profiles,
        private readonly LearnerQualificationRepository $qualifications,
        private readonly VerificationAuditLogRepository $verificationAudit,
    ) {
    }

    public function queue(
        AuthContext $auth,
        ?string $filter = null,
        int $page = 1,
        int $perPage = 50,
    ): ReviewerQueuePage {
        $this->access->requirePermission($auth, 'reviewer.queue.view');
        $reviewerUserId = $this->access->requireUserId($auth);

        $normalizedFilter = ReviewerQueueFilter::normalize($filter);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $items = $this->queueQuery->listForReviewer(
            $reviewerUserId,
            $normalizedFilter,
            $now,
            $perPage,
            $offset,
        );

        return new ReviewerQueuePage(
            items: $items,
            filter: $normalizedFilter,
            page: $page,
            perPage: $perPage,
        );
    }

    public function detail(AuthContext $auth, int $applicationId): ReviewerApplicationDetailView
    {
        $this->access->requirePermission($auth, 'reviewer.application.view');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $application = $this->access->loadApplication($applicationId);
        $this->access->assertApplicationInScope($auth, $application, $now);

        $courseVersion = $this->courseVersions->findById($application->courseVersionId);
        $batch = $this->batches->findById($application->batchId);
        $requirements = $this->requirements->listByCourseVersionId($application->courseVersionId);
        $currentDocs = $this->submissions->listCurrentForApplication($applicationId);
        $docsByRequirement = [];
        foreach ($currentDocs as $doc) {
            $docsByRequirement[$doc->requirementId] = $doc;
        }

        $profile = $this->profiles->findByUserId($application->userId);
        $qualifications = $profile !== null
            ? $this->qualifications->listByProfileId($profile->learnerProfileId)
            : [];

        $checklist = [];
        foreach ($requirements as $requirement) {
            $doc = $docsByRequirement[$requirement->requirementId] ?? null;
            $checklist[] = new ReviewerDocumentChecklistItem(
                requirementId: $requirement->requirementId,
                documentName: $requirement->documentName,
                mandatory: $requirement->mandatory,
                documentSubmissionId: $doc?->documentSubmissionId,
                displayFilename: $doc?->displayFilename,
                status: $doc?->status,
                scanStatus: $doc?->scanStatus,
                rejectionReasonCode: $doc?->rejectionReasonCode,
                learnerVisibleMessage: $doc?->learnerVisibleMessage,
                submittedAt: $doc?->submittedAt,
                reviewedAt: $doc?->reviewedAt,
            );
        }

        $history = [];
        foreach ($this->verificationAudit->listByApplication($applicationId) as $entry) {
            $history[] = new ReviewerVerificationHistoryItem(
                verificationAuditId: $entry->verificationAuditId,
                action: $entry->action,
                documentSubmissionId: $entry->documentSubmissionId,
                requirementId: $entry->requirementId,
                reviewerUserId: $entry->reviewerUserId,
                statusBefore: $entry->statusBefore,
                statusAfter: $entry->statusAfter,
                reasonCode: $entry->reasonCode,
                learnerVisibleMessage: $entry->learnerVisibleMessage,
                internalNote: $entry->internalNote,
                stateVersion: $entry->stateVersion,
                createdAt: $entry->createdAt,
            );
        }

        return new ReviewerApplicationDetailView(
            application: $application,
            courseTitle: $courseVersion !== null ? $courseVersion->title : '',
            batchLabel: $batch !== null ? $batch->name : '',
            profileSummary: $profile,
            qualifications: $qualifications,
            documentChecklist: $checklist,
            verificationHistory: $history,
            activeAssignment: $this->assignments->findActiveForApplication($applicationId),
            submittedAt: $application->submittedAt,
        );
    }
}
