<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\DocumentAuditPayload;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStateMachine;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Review\DocumentReviewPolicy;
use Academy\Domain\Review\ReviewNoteSanitizer;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Review\VerificationAuditLogWrite;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class DocumentReviewService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ReviewerAccessGuard $access,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly DocumentReviewPolicy $reviewPolicy,
        private readonly DocumentSubmissionStateMachine $documentStateMachine,
        private readonly VerificationAuditLogRepository $verificationAudit,
        private readonly AuditService $audit,
    ) {
    }

    public function verify(
        AuthContext $auth,
        int $applicationId,
        int $submissionId,
        ?string $internalNote = null,
        ?int $expectedRowVersion = null,
    ): DocumentSubmission {
        return $this->applyDecision(
            $auth,
            $applicationId,
            $submissionId,
            DocumentSubmissionStatus::APPROVED,
            null,
            null,
            $internalNote,
            'document.verified',
            'verified',
            $expectedRowVersion,
        );
    }

    public function reject(
        AuthContext $auth,
        int $applicationId,
        int $submissionId,
        string $reasonCode,
        ?string $learnerMessage = null,
        ?string $internalNote = null,
        ?int $expectedRowVersion = null,
    ): DocumentSubmission {
        DocumentRejectionReasonCode::assertValid($reasonCode);

        return $this->applyDecision(
            $auth,
            $applicationId,
            $submissionId,
            DocumentSubmissionStatus::REJECTED,
            $reasonCode,
            $learnerMessage,
            $internalNote,
            'document.rejected',
            'rejected',
            $expectedRowVersion,
        );
    }

    public function requestResubmission(
        AuthContext $auth,
        int $applicationId,
        int $submissionId,
        string $reasonCode,
        ?string $learnerMessage = null,
        ?string $internalNote = null,
        ?int $expectedRowVersion = null,
    ): DocumentSubmission {
        DocumentRejectionReasonCode::assertValid($reasonCode);

        return $this->applyDecision(
            $auth,
            $applicationId,
            $submissionId,
            DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
            $reasonCode,
            $learnerMessage,
            $internalNote,
            'document.resubmission_requested',
            'resubmission_requested',
            $expectedRowVersion,
        );
    }

    private function applyDecision(
        AuthContext $auth,
        int $applicationId,
        int $submissionId,
        string $toStatus,
        ?string $reasonCode,
        ?string $learnerMessage,
        ?string $internalNote,
        string $auditAction,
        string $verificationAction,
        ?int $expectedRowVersion = null,
    ): DocumentSubmission {
        $this->access->requirePermission($auth, 'reviewer.document.review');
        $reviewerUserId = $this->access->requireUserId($auth);

        $sanitizedLearnerMessage = ReviewNoteSanitizer::sanitizeLearnerVisible($learnerMessage);
        $sanitizedInternalNote = ReviewNoteSanitizer::sanitizeInternal($internalNote);

        return $this->transactions->run(function () use (
            $auth,
            $reviewerUserId,
            $applicationId,
            $submissionId,
            $toStatus,
            $reasonCode,
            $sanitizedLearnerMessage,
            $sanitizedInternalNote,
            $auditAction,
            $verificationAction,
            $expectedRowVersion,
        ): DocumentSubmission {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->access->loadApplicationForUpdate($applicationId);
            $this->access->assertApplicationInScope($auth, $application, $now);
            $assignment = $this->access->assertActiveClaimOwnedBy(
                $reviewerUserId,
                $this->access->lockActiveAssignment($applicationId),
            );

            $submission = $this->submissions->findById($submissionId);
            if ($submission === null || $submission->applicationId !== $applicationId || !$submission->isCurrent()) {
                throw new NotFoundException('Document submission not found.');
            }

            $locked = $this->submissions->lockCurrentForUpdate($applicationId, $submission->requirementId);
            if ($locked === null || $locked->documentSubmissionId !== $submissionId) {
                throw new ConflictException('Document was updated concurrently. Refresh and try again.');
            }

            if ($expectedRowVersion !== null && $locked->rowVersion !== $expectedRowVersion) {
                throw new ConflictException('Document was updated concurrently. Refresh and try again.');
            }

            $this->reviewPolicy->assertCanReview($application, $locked);

            $this->documentStateMachine->transition(
                $locked->status,
                $toStatus,
                $locked->scanStatus,
                $now,
                $reasonCode,
            );

            if (!$this->submissions->applyReviewDecision(
                $locked->documentSubmissionId,
                $locked->rowVersion,
                $toStatus,
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
                action: $verificationAction,
                createdAt: $now,
                documentSubmissionId: $locked->documentSubmissionId,
                requirementId: $locked->requirementId,
                statusBefore: $locked->status,
                statusAfter: $toStatus,
                reasonCode: $reasonCode,
                learnerVisibleMessage: $sanitizedLearnerMessage,
                internalNote: $sanitizedInternalNote,
                stateVersion: $application->stateVersion,
                rowVersion: $locked->rowVersion + 1,
            ));

            $this->audit->record(
                new DocumentAuditPayload(
                    action: $auditAction,
                    entityType: 'document_submission',
                    entityId: (string) $locked->documentSubmissionId,
                    previous: [
                        'document_submission_id' => $locked->documentSubmissionId,
                        'application_id' => $applicationId,
                        'requirement_id' => $locked->requirementId,
                        'status' => $locked->status,
                        'row_version' => $locked->rowVersion,
                    ],
                    next: [
                        'user_id' => $reviewerUserId,
                        'application_id' => $applicationId,
                        'requirement_id' => $locked->requirementId,
                        'document_submission_id' => $locked->documentSubmissionId,
                        'assignment_id' => $assignment->assignmentId,
                        'reviewer_user_id' => $reviewerUserId,
                        'status' => $toStatus,
                        'reason_code' => $reasonCode,
                        'row_version' => $locked->rowVersion + 1,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $reviewerUserId,
                source: 'review',
            );

            $updated = $this->submissions->findById($locked->documentSubmissionId);
            if ($updated === null) {
                throw new NotFoundException('Document submission not found.');
            }

            return $updated;
        });
    }
}
