<?php

declare(strict_types=1);

namespace Academy\Application\Admissions;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationSubmissionPreconditions;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Domain\Identity\ProfileCompleteness;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;
use DateTimeZone;

final class ApplicationWorkspaceService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ApplicationRepository $applications,
        private readonly CourseDocumentRequirementRepository $requirements,
        private readonly DocumentSubmissionRepository $submissions,
        private readonly LearnerProfileRepository $profiles,
        private readonly LearnerQualificationRepository $qualifications,
        private readonly UserWriteRepository $users,
        private readonly ApplicationSubmissionPreconditions $preconditions,
        private readonly ProfileCompletenessCalculator $completeness,
        private readonly string $declarationVersion,
    ) {
    }

    public function getOwn(AuthContext $auth, int $applicationId): ApplicationWorkspaceView
    {
        $this->authorization->require($auth, 'application.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }

        $requirements = $this->requirements->listByCourseVersionId($application->courseVersionId);
        $currentDocs = $this->submissions->listCurrentForApplication($applicationId);
        $docsByRequirement = [];
        foreach ($currentDocs as $doc) {
            $docsByRequirement[$doc->requirementId] = $doc;
        }

        $user = $this->users->findById($userId);
        $profile = $this->profiles->findByUserId($userId);
        $quals = $profile !== null
            ? $this->qualifications->listByProfileId($profile->learnerProfileId)
            : [];
        $profileCompleteness = $profile !== null
            ? $this->completeness->calculate($profile, $quals)
            : new ProfileCompleteness(0, [], ['personal', 'professional'], ['profile']);

        $emailVerifiedAt = ($user !== null && $user['email_verified_at'] !== null)
            ? new DateTimeImmutable((string) $user['email_verified_at'], new DateTimeZone('UTC'))
            : null;
        $mobileVerifiedAt = ($user !== null && $user['mobile_verified_at'] !== null)
            ? new DateTimeImmutable((string) $user['mobile_verified_at'], new DateTimeZone('UTC'))
            : null;

        if ($user !== null && $profile !== null) {
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
        } else {
            $blockers = ['profile_incomplete'];
        }

        $declarationAccepted = $application->declarationAcceptedVersion === $this->declarationVersion;

        return new ApplicationWorkspaceView(
            application: $application,
            requirements: $requirements,
            currentDocumentsByRequirementId: $docsByRequirement,
            profileCompleteness: $profileCompleteness,
            blockers: $blockers,
            requiredDeclarationVersion: $this->declarationVersion,
            declarationAccepted: $declarationAccepted,
        );
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
