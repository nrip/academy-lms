<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Audit\ReviewerAuditPayload;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStateMachine;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Outbox\ReviewerOutboxEventTypes;
use Academy\Domain\Review\DocumentReviewPolicy;
use Academy\Domain\Review\ReviewNoteSanitizer;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Review\VerificationAuditLogWrite;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class ApplicationCorrectionRequestService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ReviewerAccessGuard $access,
        private readonly ApplicationRepository $applications,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly DocumentReviewPolicy $reviewPolicy,
        private readonly DocumentSubmissionStateMachine $documentStateMachine,
        private readonly ApplicationStateMachine $applicationStateMachine,
        private readonly VerificationAuditLogRepository $verificationAudit,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param list<int> $requirementIds
     */
    public function requestCorrection(
        AuthContext $auth,
        int $applicationId,
        array $requirementIds,
        string $reasonCode,
        ?string $learnerMessage = null,
        ?string $internalNote = null,
        int $expectedStateVersion = 0,
    ): Application {
        if ($requirementIds === []) {
            throw new ValidationException('Please select at least one document requirement.', [
                'requirement_ids' => ['At least one requirement is required.'],
            ]);
        }

        DocumentRejectionReasonCode::assertValid($reasonCode);
        $this->access->requirePermission($auth, 'reviewer.document.review');
        $reviewerUserId = $this->access->requireUserId($auth);

        $sanitizedLearnerMessage = ReviewNoteSanitizer::sanitizeLearnerVisible($learnerMessage);
        $sanitizedInternalNote = ReviewNoteSanitizer::sanitizeInternal($internalNote);
        $uniqueRequirementIds = array_values(array_unique($requirementIds));

        return $this->transactions->run(function () use (
            $auth,
            $reviewerUserId,
            $applicationId,
            $uniqueRequirementIds,
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

            if ($application->status === ApplicationStatus::RESUBMISSION_REQUESTED) {
                if ($application->stateVersion !== $expectedStateVersion) {
                    throw new ConflictException('Application was updated concurrently. Refresh and try again.');
                }

                throw new ConflictException('Application already has a correction request in progress.');
            }

            if ($application->status !== ApplicationStatus::UNDER_REVIEW) {
                throw new DomainRuleException('Application is not under review.');
            }

            if ($application->stateVersion !== $expectedStateVersion) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            foreach ($uniqueRequirementIds as $requirementId) {
                $submission = $this->submissions->lockCurrentForUpdate($applicationId, $requirementId);
                if ($submission === null) {
                    throw new NotFoundException('Document submission not found for requirement.');
                }

                $this->reviewPolicy->assertCanReview($application, $submission);

                $this->documentStateMachine->transition(
                    $submission->status,
                    DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
                    $submission->scanStatus,
                    $now,
                    $reasonCode,
                );

                if (!$this->submissions->applyReviewDecision(
                    $submission->documentSubmissionId,
                    $submission->rowVersion,
                    DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
                    $reasonCode,
                    $sanitizedLearnerMessage,
                    $reviewerUserId,
                    $now,
                )) {
                    throw new ConflictException('Document was updated concurrently. Refresh and try again.');
                }

                $this->verificationAudit->append(new VerificationAuditLogWrite(
                    applicationId: $applicationId,
                    reviewerUserId: $reviewerUserId,
                    action: 'resubmission_requested',
                    createdAt: $now,
                    documentSubmissionId: $submission->documentSubmissionId,
                    requirementId: $requirementId,
                    statusBefore: $submission->status,
                    statusAfter: DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
                    reasonCode: $reasonCode,
                    learnerVisibleMessage: $sanitizedLearnerMessage,
                    internalNote: $sanitizedInternalNote,
                    stateVersion: $application->stateVersion,
                    rowVersion: $submission->rowVersion + 1,
                ));

                $this->audit->record(
                    new ReviewerAuditPayload(
                        action: 'document.resubmission_requested',
                        entityType: 'document_submission',
                        entityId: (string) $submission->documentSubmissionId,
                        previous: [
                            'status' => $submission->status,
                            'row_version' => $submission->rowVersion,
                        ],
                        next: [
                            'user_id' => $reviewerUserId,
                            'application_id' => $applicationId,
                            'requirement_id' => $requirementId,
                            'document_submission_id' => $submission->documentSubmissionId,
                            'assignment_id' => $assignment->assignmentId,
                            'status' => DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
                            'reason_code' => $reasonCode,
                            'result' => 'ok',
                        ],
                    ),
                    actorType: 'user',
                    actorUserId: $reviewerUserId,
                    source: 'review',
                );
            }

            $this->applicationStateMachine->transition(
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::RESUBMISSION_REQUESTED,
                ['reviewer'],
                $now,
                $reasonCode,
            );

            if (!$this->applications->applyTransition(
                $applicationId,
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::RESUBMISSION_REQUESTED,
                null,
                $expectedStateVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $this->outbox->enqueue(
                ReviewerOutboxEventTypes::APPLICATION_CORRECTION_REQUESTED,
                'application',
                (string) $applicationId,
                [
                    'application_id' => $applicationId,
                    'requirement_ids' => $uniqueRequirementIds,
                    'reason_code' => $reasonCode,
                    'status' => ApplicationStatus::RESUBMISSION_REQUESTED,
                ],
                ReviewerOutboxEventTypes::APPLICATION_CORRECTION_REQUESTED . ':' . $applicationId . ':' . $expectedStateVersion,
            );

            $this->verificationAudit->append(new VerificationAuditLogWrite(
                applicationId: $applicationId,
                reviewerUserId: $reviewerUserId,
                action: 'correction_requested',
                createdAt: $now,
                statusBefore: ApplicationStatus::UNDER_REVIEW,
                statusAfter: ApplicationStatus::RESUBMISSION_REQUESTED,
                reasonCode: $reasonCode,
                learnerVisibleMessage: $sanitizedLearnerMessage,
                internalNote: $sanitizedInternalNote,
                stateVersion: $expectedStateVersion + 1,
            ));

            $this->audit->record(
                new ReviewerAuditPayload(
                    action: 'application.correction_requested',
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
                        'status' => ApplicationStatus::RESUBMISSION_REQUESTED,
                        'state_version' => $expectedStateVersion + 1,
                        'reason_code' => $reasonCode,
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
}
