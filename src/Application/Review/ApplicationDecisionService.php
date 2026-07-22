<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Audit\ReviewerAuditPayload;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Credentials\ApplicationRejectionReasonCode;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Outbox\ReviewerOutboxEventTypes;
use Academy\Domain\Review\ApplicationDecisionPreconditions;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Domain\Review\ReviewNoteSanitizer;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Review\VerificationAuditLogWrite;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class ApplicationDecisionService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ReviewerAccessGuard $access,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationReviewAssignmentRepository $assignments,
        private readonly CourseDocumentRequirementRepository $requirements,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly ApplicationDecisionPreconditions $preconditions,
        private readonly ApplicationStateMachine $applicationStateMachine,
        private readonly VerificationAuditLogRepository $verificationAudit,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
    ) {
    }

    public function approve(
        AuthContext $auth,
        int $applicationId,
        int $expectedStateVersion,
    ): Application {
        $this->access->requirePermission($auth, 'reviewer.application.approve');
        $reviewerUserId = $this->access->requireUserId($auth);

        return $this->transactions->run(function () use (
            $auth,
            $reviewerUserId,
            $applicationId,
            $expectedStateVersion,
        ): Application {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->access->loadApplicationForUpdate($applicationId);
            $this->access->assertApplicationInScope($auth, $application, $now);
            $assignment = $this->access->assertActiveClaimOwnedBy(
                $reviewerUserId,
                $this->access->lockActiveAssignment($applicationId),
            );
            $currentDocs = $this->submissions->lockAllCurrentForApplication($applicationId);
            $requirements = $this->requirements->listByCourseVersionId($application->courseVersionId);

            $blockers = $this->preconditions->evaluate(
                $application,
                $currentDocs,
                $requirements,
                $assignment,
                $reviewerUserId,
            );
            $this->preconditions->assertApproveAllowed($blockers);

            $this->applicationStateMachine->transition(
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::PAYMENT_PENDING,
                ['reviewer'],
                $now,
            );

            if (!$this->applications->applyTransition(
                $applicationId,
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::PAYMENT_PENDING,
                null,
                $expectedStateVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            if (!$this->assignments->complete(
                $assignment->assignmentId,
                $assignment->rowVersion,
                $now,
            )) {
                throw new ConflictException('Claim was updated concurrently. Refresh and try again.');
            }

            $this->outbox->enqueue(
                ReviewerOutboxEventTypes::APPLICATION_APPROVED,
                'application',
                (string) $applicationId,
                [
                    'application_id' => $applicationId,
                    'status' => ApplicationStatus::PAYMENT_PENDING,
                ],
                ReviewerOutboxEventTypes::APPLICATION_APPROVED . ':' . $applicationId . ':' . $expectedStateVersion,
            );

            $this->verificationAudit->append(new VerificationAuditLogWrite(
                applicationId: $applicationId,
                reviewerUserId: $reviewerUserId,
                action: 'application_approved',
                createdAt: $now,
                statusBefore: ApplicationStatus::UNDER_REVIEW,
                statusAfter: ApplicationStatus::PAYMENT_PENDING,
                stateVersion: $expectedStateVersion + 1,
            ));

            $this->audit->record(
                new ReviewerAuditPayload(
                    action: 'application.approved',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: [
                        'status' => ApplicationStatus::UNDER_REVIEW,
                        'state_version' => $expectedStateVersion,
                    ],
                    next: [
                        'user_id' => $reviewerUserId,
                        'application_id' => $applicationId,
                        'application_number' => $application->applicationNumber,
                        'assignment_id' => $assignment->assignmentId,
                        'status' => ApplicationStatus::PAYMENT_PENDING,
                        'state_version' => $expectedStateVersion + 1,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $reviewerUserId,
                source: 'review',
            );

            $final = $this->applications->findById($applicationId);
            if ($final === null) {
                throw new NotFoundException('Application not found.');
            }

            return $final;
        });
    }

    public function reject(
        AuthContext $auth,
        int $applicationId,
        string $reasonCode,
        ?string $learnerMessage = null,
        ?string $internalNote = null,
        int $expectedStateVersion = 0,
    ): Application {
        ApplicationRejectionReasonCode::assertValid($reasonCode);
        $this->access->requirePermission($auth, 'reviewer.application.reject');
        $reviewerUserId = $this->access->requireUserId($auth);

        $sanitizedLearnerMessage = ReviewNoteSanitizer::sanitizeLearnerVisible($learnerMessage);
        $sanitizedInternalNote = ReviewNoteSanitizer::sanitizeInternal($internalNote);

        return $this->transactions->run(function () use (
            $auth,
            $reviewerUserId,
            $applicationId,
            $reasonCode,
            $sanitizedLearnerMessage,
            $sanitizedInternalNote,
            $expectedStateVersion,
        ): Application {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->access->loadApplicationForUpdate($applicationId);
            $this->access->assertApplicationInScope($auth, $application, $now);
            $assignment = $this->access->assertActiveClaimOwnedBy(
                $reviewerUserId,
                $this->access->lockActiveAssignment($applicationId),
            );

            if ($application->status !== ApplicationStatus::UNDER_REVIEW) {
                throw new ConflictException('Application is not under review.');
            }

            if ($application->stateVersion !== $expectedStateVersion) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $this->submissions->lockAllCurrentForApplication($applicationId);

            $this->applicationStateMachine->transition(
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::REJECTED,
                ['reviewer'],
                $now,
                $reasonCode,
            );

            if (!$this->applications->applyTransition(
                $applicationId,
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::REJECTED,
                null,
                $expectedStateVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            if (!$this->assignments->complete(
                $assignment->assignmentId,
                $assignment->rowVersion,
                $now,
            )) {
                throw new ConflictException('Claim was updated concurrently. Refresh and try again.');
            }

            $this->outbox->enqueue(
                ReviewerOutboxEventTypes::APPLICATION_REJECTED,
                'application',
                (string) $applicationId,
                [
                    'application_id' => $applicationId,
                    'reason_code' => $reasonCode,
                    'status' => ApplicationStatus::REJECTED,
                ],
                ReviewerOutboxEventTypes::APPLICATION_REJECTED . ':' . $applicationId . ':' . $expectedStateVersion,
            );

            $this->verificationAudit->append(new VerificationAuditLogWrite(
                applicationId: $applicationId,
                reviewerUserId: $reviewerUserId,
                action: 'application_rejected',
                createdAt: $now,
                statusBefore: ApplicationStatus::UNDER_REVIEW,
                statusAfter: ApplicationStatus::REJECTED,
                reasonCode: $reasonCode,
                learnerVisibleMessage: $sanitizedLearnerMessage,
                internalNote: $sanitizedInternalNote,
                stateVersion: $expectedStateVersion + 1,
            ));

            $this->audit->record(
                new ReviewerAuditPayload(
                    action: 'application.rejected',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: [
                        'status' => ApplicationStatus::UNDER_REVIEW,
                        'state_version' => $expectedStateVersion,
                    ],
                    next: [
                        'user_id' => $reviewerUserId,
                        'application_id' => $applicationId,
                        'application_number' => $application->applicationNumber,
                        'assignment_id' => $assignment->assignmentId,
                        'status' => ApplicationStatus::REJECTED,
                        'state_version' => $expectedStateVersion + 1,
                        'reason_code' => $reasonCode,
                        'result' => 'ok',
                    ],
                    reason: $reasonCode,
                ),
                actorType: 'user',
                actorUserId: $reviewerUserId,
                source: 'review',
            );

            $final = $this->applications->findById($applicationId);
            if ($final === null) {
                throw new NotFoundException('Application not found.');
            }

            return $final;
        });
    }
}
