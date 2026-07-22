<?php

declare(strict_types=1);

namespace Academy\Application\Admissions;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Admissions\ApplicationSubmissionPreconditions;
use Academy\Domain\Audit\AdmissionsAuditPayload;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Outbox\DocumentOutboxEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class ApplicationSubmitService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly CourseDocumentRequirementRepository $requirements,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly LearnerProfileRepository $profiles,
        private readonly LearnerQualificationRepository $qualifications,
        private readonly UserWriteRepository $users,
        private readonly ApplicationSubmissionPreconditions $preconditions,
        private readonly ApplicationStateMachine $applicationStateMachine,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
    ) {
    }

    public function submit(AuthContext $auth, int $applicationId): Application
    {
        $this->authorization->require($auth, 'application.submit_own');
        $userId = $this->requireUserId($auth);

        return $this->transactions->run(function () use ($userId, $applicationId): Application {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $application = $this->applications->findByIdForUpdate($applicationId);
            if ($application === null || $application->userId !== $userId) {
                throw new NotFoundException('Application not found.');
            }

            if (in_array($application->status, [
                ApplicationStatus::SUBMITTED,
                ApplicationStatus::UNDER_REVIEW,
                ApplicationStatus::DOCUMENTS_INCOMPLETE,
                ApplicationStatus::PAYMENT_PENDING,
            ], true)) {
                throw new ConflictException('Application has already been submitted.');
            }

            $expectedStateVersion = $application->stateVersion;

            $user = $this->users->findByIdForUpdate($userId);
            if ($user === null) {
                throw new NotFoundException('Application not found.');
            }

            $profile = $this->profiles->findByUserId($userId);
            if ($profile === null) {
                throw new DomainRuleException('Application cannot be submitted: profile_incomplete');
            }
            $quals = $this->qualifications->listByProfileId($profile->learnerProfileId);
            $requirements = $this->requirements->listByCourseVersionId($application->courseVersionId);
            $currentDocs = $this->submissions->lockAllCurrentForApplication($applicationId);

            $emailVerifiedAt = $user['email_verified_at'] === null
                ? null
                : new DateTimeImmutable((string) $user['email_verified_at'], new DateTimeZone('UTC'));
            $mobileVerifiedAt = $user['mobile_verified_at'] === null
                ? null
                : new DateTimeImmutable((string) $user['mobile_verified_at'], new DateTimeZone('UTC'));

            $blockers = $this->preconditions->evaluate(
                $application,
                (string) $user['account_status'],
                $emailVerifiedAt,
                $mobileVerifiedAt,
                $profile,
                $quals,
                $requirements,
                $currentDocs,
                $application->declarationAcceptedVersion,
            );
            $this->preconditions->assertSatisfied($blockers);

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
                    throw new ConflictException('Document was updated concurrently during submit.');
                }
            }

            $this->applicationStateMachine->transition(
                ApplicationStatus::DRAFT,
                ApplicationStatus::SUBMITTED,
                ['learner'],
                $now,
            );

            if (!$this->applications->applyTransition(
                $applicationId,
                ApplicationStatus::DRAFT,
                ApplicationStatus::SUBMITTED,
                $now,
                $expectedStateVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $afterSubmitVersion = $expectedStateVersion + 1;

            $this->applicationStateMachine->transition(
                ApplicationStatus::SUBMITTED,
                ApplicationStatus::UNDER_REVIEW,
                ['system'],
                $now,
            );

            if (!$this->applications->applyTransition(
                $applicationId,
                ApplicationStatus::SUBMITTED,
                ApplicationStatus::UNDER_REVIEW,
                null,
                $afterSubmitVersion,
                $now,
            )) {
                throw new ConflictException('Application was updated concurrently. Refresh and try again.');
            }

            $this->outbox->enqueue(
                DocumentOutboxEventTypes::APPLICATION_SUBMITTED,
                'application',
                (string) $applicationId,
                [
                    'application_id' => $applicationId,
                    'status' => ApplicationStatus::UNDER_REVIEW,
                ],
                DocumentOutboxEventTypes::APPLICATION_SUBMITTED . ':' . $applicationId,
            );

            $this->audit->record(
                new AdmissionsAuditPayload(
                    action: 'application.submitted',
                    entityType: 'application',
                    entityId: (string) $applicationId,
                    previous: [
                        'status' => ApplicationStatus::DRAFT,
                        'state_version' => $expectedStateVersion,
                    ],
                    next: [
                        'user_id' => $userId,
                        'application_id' => $applicationId,
                        'application_number' => $application->applicationNumber,
                        'status' => ApplicationStatus::UNDER_REVIEW,
                        'state_version' => $afterSubmitVersion + 1,
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
