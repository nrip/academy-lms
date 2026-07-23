<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Audit\AdmissionsAuditPayload;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Outbox\ReviewerOutboxEventTypes;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class LearnerCorrectionResubmitService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly ApplicationStateMachine $applicationStateMachine,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
    ) {
    }

    public function resubmit(
        AuthContext $auth,
        int $applicationId,
        int $expectedStateVersion,
    ): Application {
        $this->authorization->require($auth, 'application.resubmit_corrections_own');
        $userId = $this->requireUserId($auth);

        return $this->transactions->run(function () use (
            $userId,
            $applicationId,
            $expectedStateVersion,
        ): Application {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->applications->findByIdForUpdate($applicationId);
            if ($application === null || $application->userId !== $userId) {
                throw new NotFoundException('Application not found.');
            }

            if ($application->status !== ApplicationStatus::RESUBMISSION_REQUESTED) {
                throw new DomainRuleException('Application is not awaiting correction resubmission.');
            }

            if ($application->stateVersion !== $expectedStateVersion) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $currentDocs = $this->submissions->lockAllCurrentForApplication($applicationId);

            foreach ($currentDocs as $doc) {
                if (!$doc->isCurrent()) {
                    continue;
                }

                if (in_array($doc->status, [
                    DocumentSubmissionStatus::REJECTED,
                    DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
                ], true)) {
                    throw new DomainRuleException(
                        'All corrected documents must be re-uploaded and scanned before resubmitting.',
                    );
                }

                if ($doc->scanStatus === DocumentScanStatus::PENDING) {
                    throw new DomainRuleException('Document security scans are still in progress.');
                }

                if ($doc->scanStatus === DocumentScanStatus::FAILED
                    || $doc->status === DocumentSubmissionStatus::FAILED_SECURITY_SCAN
                ) {
                    throw new DomainRuleException('A corrected document failed security scanning.');
                }
            }

            foreach ($currentDocs as $doc) {
                if ($doc->status !== DocumentSubmissionStatus::UPLOADED
                    || $doc->scanStatus !== DocumentScanStatus::CLEAN
                ) {
                    continue;
                }

                if (!$this->submissions->promoteUploadedToUnderReview(
                    $doc->documentSubmissionId,
                    $doc->rowVersion,
                    $now,
                )) {
                    throw new ConflictException('Document was updated concurrently during resubmit.');
                }
            }

            $this->applicationStateMachine->transition(
                ApplicationStatus::RESUBMISSION_REQUESTED,
                ApplicationStatus::UNDER_REVIEW,
                ['system'],
                $now,
            );

            if (!$this->applications->applyTransition(
                $applicationId,
                ApplicationStatus::RESUBMISSION_REQUESTED,
                ApplicationStatus::UNDER_REVIEW,
                null,
                $expectedStateVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $this->outbox->enqueue(
                ReviewerOutboxEventTypes::APPLICATION_CORRECTIONS_RESUBMITTED,
                'application',
                (string) $applicationId,
                [
                    'application_id' => $applicationId,
                    'status' => ApplicationStatus::UNDER_REVIEW,
                ],
                ReviewerOutboxEventTypes::APPLICATION_CORRECTIONS_RESUBMITTED . ':' . $applicationId . ':' . $expectedStateVersion,
            );

            $this->audit->record(
                new AdmissionsAuditPayload(
                    action: 'application.corrections_resubmitted',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: [
                        'status' => ApplicationStatus::RESUBMISSION_REQUESTED,
                        'state_version' => $expectedStateVersion,
                    ],
                    next: [
                        'user_id' => $userId,
                        'application_id' => $applicationId,
                        'application_number' => $application->applicationNumber,
                        'status' => ApplicationStatus::UNDER_REVIEW,
                        'state_version' => $expectedStateVersion + 1,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'admissions',
            );

            $final = $this->applications->findById($applicationId);
            if ($final === null) {
                throw new NotFoundException('Application not found.');
            }

            return $final;
        });
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
