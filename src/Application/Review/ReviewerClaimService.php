<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\ReviewerAuditPayload;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Review\ApplicationReviewAssignment;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Review\VerificationAuditLogWrite;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class ReviewerClaimService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ReviewerAccessGuard $access,
        private readonly ApplicationReviewAssignmentRepository $assignments,
        private readonly VerificationAuditLogRepository $verificationAudit,
        private readonly AuditService $audit,
    ) {
    }

    public function claim(AuthContext $auth, int $applicationId): ApplicationReviewAssignment
    {
        $this->access->requirePermission($auth, 'reviewer.application.claim');
        $reviewerUserId = $this->access->requireUserId($auth);

        return $this->transactions->run(function () use ($auth, $reviewerUserId, $applicationId): ApplicationReviewAssignment {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->access->loadApplicationForUpdate($applicationId);
            $this->access->assertApplicationInScope($auth, $application, $now);
            $this->access->assertClaimableStatus($application);

            $assignment = $this->assignments->claim($applicationId, $reviewerUserId, $now);

            $this->audit->record(
                new ReviewerAuditPayload(
                    action: 'reviewer.application_claimed',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: ['assignment_id' => null],
                    next: [
                        'user_id' => $reviewerUserId,
                        'application_id' => $applicationId,
                        'application_number' => $application->applicationNumber,
                        'assignment_id' => $assignment->assignmentId,
                        'reviewer_user_id' => $reviewerUserId,
                        'status' => $application->status,
                        'state_version' => $application->stateVersion,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $reviewerUserId,
                source: 'review',
            );

            $this->verificationAudit->append(new VerificationAuditLogWrite(
                applicationId: $applicationId,
                reviewerUserId: $reviewerUserId,
                action: 'claimed',
                createdAt: $now,
                statusBefore: $application->status,
                statusAfter: $application->status,
                stateVersion: $application->stateVersion,
            ));

            return $assignment;
        });
    }

    public function release(
        AuthContext $auth,
        int $applicationId,
        int $expectedAssignmentRowVersion,
    ): void {
        $this->access->requirePermission($auth, 'reviewer.application.claim');
        $reviewerUserId = $this->access->requireUserId($auth);

        $this->transactions->run(function () use (
            $auth,
            $reviewerUserId,
            $applicationId,
            $expectedAssignmentRowVersion,
        ): void {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->access->loadApplicationForUpdate($applicationId);
            $this->access->assertApplicationInScope($auth, $application, $now);

            $assignment = $this->assignments->lockActiveForApplication($applicationId);
            if ($assignment === null) {
                throw new ConflictException('Application has no active claim to release.');
            }

            if ($assignment->reviewerUserId !== $reviewerUserId) {
                throw new ConflictException('Application is claimed by another reviewer.');
            }

            if (!$this->assignments->release(
                $assignment->assignmentId,
                $expectedAssignmentRowVersion,
                $now,
            )) {
                throw new ConflictException('Claim was updated concurrently. Refresh and try again.');
            }

            $this->audit->record(
                new ReviewerAuditPayload(
                    action: 'reviewer.application_released',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: [
                        'assignment_id' => $assignment->assignmentId,
                        'reviewer_user_id' => $assignment->reviewerUserId,
                        'row_version' => $expectedAssignmentRowVersion,
                    ],
                    next: [
                        'user_id' => $reviewerUserId,
                        'application_id' => $applicationId,
                        'application_number' => $application->applicationNumber,
                        'assignment_id' => $assignment->assignmentId,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $reviewerUserId,
                source: 'review',
            );

            $this->verificationAudit->append(new VerificationAuditLogWrite(
                applicationId: $applicationId,
                reviewerUserId: $reviewerUserId,
                action: 'released',
                createdAt: $now,
                statusBefore: $application->status,
                statusAfter: $application->status,
                stateVersion: $application->stateVersion,
                rowVersion: $expectedAssignmentRowVersion + 1,
            ));
        });
    }
}
