<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Admissions;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Admissions\ApplicationSubmissionPreconditions;
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
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ApplicationSubmissionPreconditionsTest extends TestCase
{
    private const DECLARATION_VERSION = '2026-07-22';

    private ApplicationSubmissionPreconditions $preconditions;

    protected function setUp(): void
    {
        $this->preconditions = new ApplicationSubmissionPreconditions(
            new ProfileCompletenessCalculator(),
            self::DECLARATION_VERSION,
        );
    }

    public function testFullyEligibleDraftHasNoBlockers(): void
    {
        $requirement = $this->requirement(1, mandatory: true);
        $document = $this->document(1, DocumentSubmissionStatus::UNDER_REVIEW, DocumentScanStatus::CLEAN);

        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [$this->completeQualification()],
            [$requirement],
            [$document],
            self::DECLARATION_VERSION,
        );

        self::assertSame([], $blockers);
        $this->preconditions->assertSatisfied($blockers);
    }

    public function testNonDraftApplicationIsBlocked(): void
    {
        $application = $this->application(ApplicationStatus::SUBMITTED);

        $blockers = $this->preconditions->evaluate(
            $application,
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [],
            [],
            self::DECLARATION_VERSION,
        );

        self::assertContains('application_not_draft', $blockers);
    }

    public function testInactiveAccountIsBlocked(): void
    {
        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::SUSPENDED,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [],
            [],
            self::DECLARATION_VERSION,
        );

        self::assertContains('account_not_active', $blockers);
    }

    public function testUnverifiedEmailIsBlocked(): void
    {
        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [],
            [],
            self::DECLARATION_VERSION,
        );

        self::assertContains('email_unverified', $blockers);
    }

    public function testUnverifiedMobileIsBlocked(): void
    {
        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            null,
            $this->completeProfile(),
            [],
            [],
            [],
            self::DECLARATION_VERSION,
        );

        self::assertContains('mobile_unverified', $blockers);
    }

    public function testMissingOrWrongDeclarationVersionIsBlocked(): void
    {
        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [],
            [],
            null,
        );
        self::assertContains('declaration_required', $blockers);

        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [],
            [],
            'old-version',
        );
        self::assertContains('declaration_required', $blockers);
    }

    public function testMissingMandatoryDocumentIsBlocked(): void
    {
        $requirement = $this->requirement(1, mandatory: true);

        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [$requirement],
            [],
            self::DECLARATION_VERSION,
        );

        self::assertContains('document_missing:1', $blockers);
    }

    public function testOptionalDocumentDoesNotBlockWhenMissing(): void
    {
        $requirement = $this->requirement(1, mandatory: false);

        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [$requirement],
            [],
            self::DECLARATION_VERSION,
        );

        self::assertNotContains('document_missing:1', $blockers);
    }

    public function testPendingScanIsBlocked(): void
    {
        $requirement = $this->requirement(1, mandatory: true);
        $document = $this->document(1, DocumentSubmissionStatus::UPLOADED, DocumentScanStatus::PENDING);

        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [$requirement],
            [$document],
            self::DECLARATION_VERSION,
        );

        self::assertContains('document_scan_pending:1', $blockers);
    }

    public function testFailedScanIsBlocked(): void
    {
        $requirement = $this->requirement(1, mandatory: true);
        $document = $this->document(1, DocumentSubmissionStatus::FAILED_SECURITY_SCAN, DocumentScanStatus::FAILED);

        $blockers = $this->preconditions->evaluate(
            $this->application(),
            AccountStatus::ACTIVE,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $this->completeProfile(),
            [],
            [$requirement],
            [$document],
            self::DECLARATION_VERSION,
        );

        self::assertContains('document_scan_failed:1', $blockers);
    }

    public function testAssertSatisfiedThrowsForNonEmptyBlockers(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->preconditions->assertSatisfied(['declaration_required']);
    }

    public function testAssertSatisfiedIsNoOpForEmptyBlockers(): void
    {
        $this->preconditions->assertSatisfied([]);
        self::assertTrue(true);
    }

    private function application(string $status = ApplicationStatus::DRAFT): Application
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Application(
            applicationId: 1,
            applicationNumber: 'APP-260722-ABCDE12345',
            userId: 1,
            courseVersionId: 1,
            batchId: 1,
            status: $status,
            stateVersion: 1,
            declarationAcceptedVersion: null,
            declarationAcceptedAt: null,
            submittedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function requirement(int $requirementId, bool $mandatory): CourseDocumentRequirement
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new CourseDocumentRequirement(
            requirementId: $requirementId,
            courseVersionId: 1,
            documentName: 'Medical registration certificate',
            description: 'Upload your current registration certificate.',
            mandatory: $mandatory,
            acceptedFileTypes: 'pdf,jpg,png',
            maxSizeBytes: 10485760,
            singleOrMultiple: 'single',
            reuseAllowed: false,
            reviewerInstructions: null,
            sortOrder: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function document(int $requirementId, string $status, string $scanStatus): DocumentSubmission
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new DocumentSubmission(
            documentSubmissionId: 1,
            applicationId: 1,
            requirementId: $requirementId,
            objectKey: 'applications/1/requirements/' . $requirementId . '/doc.pdf',
            displayFilename: 'doc.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 1024,
            checksumSha256: str_repeat('a', 64),
            status: $status,
            scanStatus: $scanStatus,
            rejectionReasonCode: null,
            learnerVisibleMessage: null,
            reviewedByUserId: null,
            reviewedAt: null,
            uploadedByUserId: 1,
            submittedAt: $now,
            supersededAt: null,
            currentMarker: 1,
            rowVersion: 1,
            scanAttemptCount: 0,
            scanQueuedAt: $now,
            scanCompletedAt: null,
            scanLeaseOwner: null,
            scanLeaseToken: null,
            scanLeaseExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function completeQualification(): LearnerQualification
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new LearnerQualification(
            learnerQualificationId: 1,
            learnerProfileId: 1,
            qualificationType: 'Degree',
            qualificationName: 'MBBS',
            institutionName: 'AIIMS',
            universityOrBoard: null,
            country: null,
            completionYear: 2010,
            registrationOrCertificateNumber: null,
            displayOrder: 1,
            rowVersion: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function completeProfile(): LearnerProfile
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new LearnerProfile(
            learnerProfileId: 1,
            userId: 1,
            firstName: 'Asha',
            middleName: null,
            lastName: 'Rao',
            preferredDisplayName: 'Dr Rao',
            certificateName: 'Dr Asha Rao',
            certificateNameConfirmed: true,
            dateOfBirth: '1990-01-01',
            gender: null,
            nationality: null,
            addressLine1: '1 Road',
            addressLine2: null,
            city: 'Bengaluru',
            state: 'Karnataka',
            postalCode: '560001',
            country: 'India',
            alternateMobile: null,
            profession: 'Physician',
            speciality: 'Endocrinology',
            currentDesignation: 'Consultant',
            organizationName: 'City Hospital',
            yearsOfExperience: 10,
            medicalCouncilName: 'NMC',
            medicalCouncilRegistrationNumber: 'REG123',
            medicalCouncilRegistrationState: 'Karnataka',
            registrationValidFrom: '2020-01-01',
            registrationValidUntil: '2030-01-01',
            rowVersion: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
