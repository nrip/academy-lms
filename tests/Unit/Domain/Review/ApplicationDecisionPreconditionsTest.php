<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Review;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Review\ApplicationDecisionPreconditions;
use Academy\Domain\Review\ApplicationReviewAssignment;
use Academy\Domain\Review\ApplicationReviewAssignmentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ApplicationDecisionPreconditionsTest extends TestCase
{
    private ApplicationDecisionPreconditions $preconditions;

    protected function setUp(): void
    {
        $this->preconditions = new ApplicationDecisionPreconditions();
    }

    public function testNoBlockersWhenAllMandatoryDocsApprovedWithActiveClaim(): void
    {
        $application = $this->application();
        $requirement = $this->requirement(10);
        $doc = $this->submission(requirementId: 10, status: DocumentSubmissionStatus::APPROVED);
        $assignment = $this->assignment(reviewerUserId: 50);

        $blockers = $this->preconditions->evaluate(
            $application,
            [$doc],
            [$requirement],
            $assignment,
            50,
        );

        self::assertSame([], $blockers);
        $this->preconditions->assertApproveAllowed($blockers);
    }

    public function testBlocksWhenMandatoryDocumentNotApproved(): void
    {
        $application = $this->application();
        $requirement = $this->requirement(10);
        $doc = $this->submission(requirementId: 10, status: DocumentSubmissionStatus::UNDER_REVIEW);
        $assignment = $this->assignment(reviewerUserId: 50);

        $blockers = $this->preconditions->evaluate(
            $application,
            [$doc],
            [$requirement],
            $assignment,
            50,
        );

        self::assertContains('document_not_approved:10', $blockers);
    }

    public function testBlocksWhenMandatoryDocumentMissing(): void
    {
        $application = $this->application();
        $requirement = $this->requirement(10);
        $assignment = $this->assignment(reviewerUserId: 50);

        $blockers = $this->preconditions->evaluate(
            $application,
            [],
            [$requirement],
            $assignment,
            50,
        );

        self::assertContains('document_missing:10', $blockers);
    }

    public function testBlocksWhenNoActiveClaim(): void
    {
        $application = $this->application();
        $requirement = $this->requirement(10);
        $doc = $this->submission(requirementId: 10, status: DocumentSubmissionStatus::APPROVED);

        $blockers = $this->preconditions->evaluate(
            $application,
            [$doc],
            [$requirement],
            null,
            50,
        );

        self::assertContains('no_active_claim', $blockers);
    }

    public function testBlocksWhenClaimOwnedByAnotherReviewer(): void
    {
        $application = $this->application();
        $requirement = $this->requirement(10);
        $doc = $this->submission(requirementId: 10, status: DocumentSubmissionStatus::APPROVED);
        $assignment = $this->assignment(reviewerUserId: 99);

        $blockers = $this->preconditions->evaluate(
            $application,
            [$doc],
            [$requirement],
            $assignment,
            50,
        );

        self::assertContains('claim_not_owned_by_actor', $blockers);
    }

    public function testBlocksWhenApplicationNotUnderReview(): void
    {
        $application = $this->application(status: ApplicationStatus::PAYMENT_PENDING);
        $requirement = $this->requirement(10);
        $doc = $this->submission(requirementId: 10, status: DocumentSubmissionStatus::APPROVED);
        $assignment = $this->assignment(reviewerUserId: 50);

        $blockers = $this->preconditions->evaluate(
            $application,
            [$doc],
            [$requirement],
            $assignment,
            50,
        );

        self::assertContains('application_not_under_review', $blockers);
    }

    public function testStateVersionIsNotAPreconditionBlocker(): void
    {
        $application = $this->application(stateVersion: 3);
        $requirement = $this->requirement(10);
        $doc = $this->submission(requirementId: 10, status: DocumentSubmissionStatus::APPROVED);
        $assignment = $this->assignment(reviewerUserId: 50);

        $blockers = $this->preconditions->evaluate(
            $application,
            [$doc],
            [$requirement],
            $assignment,
            50,
        );

        self::assertSame([], $blockers);
    }

    public function testAssertApproveAllowedThrowsWithBlockers(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->preconditions->assertApproveAllowed(['no_active_claim']);
    }

    private function application(
        string $status = ApplicationStatus::UNDER_REVIEW,
        int $stateVersion = 2,
    ): Application {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new Application(
            applicationId: 1,
            applicationNumber: 'APP-UNIT-001',
            userId: 10,
            courseVersionId: 5,
            batchId: 3,
            status: $status,
            stateVersion: $stateVersion,
            submittedAt: $now,
            declarationAcceptedVersion: '2026-07-22',
            declarationAcceptedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function requirement(int $requirementId, bool $mandatory = true): CourseDocumentRequirement
    {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new CourseDocumentRequirement(
            requirementId: $requirementId,
            courseVersionId: 5,
            documentName: 'Registration certificate',
            description: 'Upload certificate',
            mandatory: $mandatory,
            acceptedFileTypes: 'pdf',
            maxSizeBytes: 5242880,
            singleOrMultiple: 'single',
            reuseAllowed: false,
            reviewerInstructions: null,
            sortOrder: 0,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function submission(
        int $requirementId,
        string $status = DocumentSubmissionStatus::UNDER_REVIEW,
        string $scanStatus = DocumentScanStatus::CLEAN,
        ?int $currentMarker = 1,
    ): DocumentSubmission {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new DocumentSubmission(
            documentSubmissionId: 100 + $requirementId,
            applicationId: 1,
            requirementId: $requirementId,
            objectKey: 'documents/test/key.pdf',
            displayFilename: 'certificate.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 2048,
            checksumSha256: hash('sha256', 'payload'),
            status: $status,
            scanStatus: $scanStatus,
            rejectionReasonCode: null,
            learnerVisibleMessage: null,
            reviewedByUserId: null,
            reviewedAt: null,
            uploadedByUserId: 10,
            submittedAt: $now,
            supersededAt: null,
            currentMarker: $currentMarker,
            rowVersion: 1,
            scanAttemptCount: 1,
            scanQueuedAt: null,
            scanCompletedAt: $now,
            scanLeaseOwner: null,
            scanLeaseToken: null,
            scanLeaseExpiresAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function assignment(int $reviewerUserId): ApplicationReviewAssignment
    {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new ApplicationReviewAssignment(
            assignmentId: 500,
            applicationId: 1,
            reviewerUserId: $reviewerUserId,
            assignmentStatus: ApplicationReviewAssignmentStatus::ACTIVE,
            claimedAt: $now,
            releasedAt: null,
            completedAt: null,
            activeMarker: 1,
            rowVersion: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
