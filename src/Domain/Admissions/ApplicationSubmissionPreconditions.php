<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Identity\LearnerQualification;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use DateTimeImmutable;

/**
 * Evaluates learner Application submit preconditions against locked/current data.
 */
final class ApplicationSubmissionPreconditions
{
    public function __construct(
        private readonly ProfileCompletenessCalculator $profileCompleteness,
        private readonly string $requiredDeclarationVersion,
    ) {
    }

    /**
     * @param list<CourseDocumentRequirement> $requirements
     * @param list<DocumentSubmission> $currentSubmissions
     * @param list<LearnerQualification> $qualifications
     * @return list<string> Blocking reason codes (empty = ok)
     */
    public function evaluate(
        Application $application,
        string $accountStatus,
        ?DateTimeImmutable $emailVerifiedAt,
        ?DateTimeImmutable $mobileVerifiedAt,
        LearnerProfile $profile,
        array $qualifications,
        array $requirements,
        array $currentSubmissions,
        ?string $declarationAcceptedVersion,
    ): array {
        $blockers = [];

        if ($application->status !== ApplicationStatus::DRAFT) {
            $blockers[] = 'application_not_draft';
        }

        if ($accountStatus !== AccountStatus::ACTIVE) {
            $blockers[] = 'account_not_active';
        }
        if ($emailVerifiedAt === null) {
            $blockers[] = 'email_unverified';
        }
        if ($mobileVerifiedAt === null) {
            $blockers[] = 'mobile_unverified';
        }

        if ($declarationAcceptedVersion === null
            || $declarationAcceptedVersion !== $this->requiredDeclarationVersion
        ) {
            $blockers[] = 'declaration_required';
        }

        $completeness = $this->profileCompleteness->calculate($profile, $qualifications);
        if ($completeness->missingRequiredFieldKeys !== []) {
            $blockers[] = 'profile_incomplete';
        }

        $byRequirement = [];
        foreach ($currentSubmissions as $submission) {
            if ($submission->isCurrent()) {
                $byRequirement[$submission->requirementId] = $submission;
            }
        }

        foreach ($requirements as $requirement) {
            if (!$requirement->mandatory) {
                continue;
            }

            $current = $byRequirement[$requirement->requirementId] ?? null;
            if ($current === null) {
                $blockers[] = 'document_missing:' . $requirement->requirementId;
                continue;
            }

            if ($current->scanStatus === DocumentScanStatus::PENDING) {
                $blockers[] = 'document_scan_pending:' . $requirement->requirementId;
                continue;
            }
            if ($current->scanStatus === DocumentScanStatus::FAILED
                || $current->status === DocumentSubmissionStatus::FAILED_SECURITY_SCAN
            ) {
                $blockers[] = 'document_scan_failed:' . $requirement->requirementId;
                continue;
            }
            if ($current->scanStatus !== DocumentScanStatus::CLEAN) {
                $blockers[] = 'document_scan_not_clean:' . $requirement->requirementId;
                continue;
            }
            if (!$current->isAcceptableForSubmission()) {
                $blockers[] = 'document_not_acceptable:' . $requirement->requirementId;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param list<string> $blockers
     */
    public function assertSatisfied(array $blockers): void
    {
        if ($blockers === []) {
            return;
        }

        throw new DomainRuleException(
            'Application cannot be submitted: ' . implode(', ', $blockers),
        );
    }
}
