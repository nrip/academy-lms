<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Review;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Review\DocumentReviewPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DocumentReviewPolicyTest extends TestCase
{
    private DocumentReviewPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DocumentReviewPolicy();
    }

    public function testAllowsCurrentCleanUnderReviewDocument(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);
        $submission = $this->submission();

        self::assertTrue($this->policy->canReview($application, $submission));
    }

    public function testAllowsDuringResubmissionRequestedApplicationStatus(): void
    {
        $application = $this->application(ApplicationStatus::RESUBMISSION_REQUESTED);
        $submission = $this->submission();

        self::assertTrue($this->policy->canReview($application, $submission));
    }

    public function testDeniesHistoricalSubmission(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);
        $submission = $this->submission(currentMarker: null, status: DocumentSubmissionStatus::SUPERSEDED);

        self::assertFalse($this->policy->canReview($application, $submission));
    }

    public function testDeniesNonCleanScan(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);
        $submission = $this->submission(scanStatus: DocumentScanStatus::PENDING);

        self::assertFalse($this->policy->canReview($application, $submission));
    }

    public function testDeniesWhenDocumentNotUnderReview(): void
    {
        $application = $this->application(ApplicationStatus::UNDER_REVIEW);
        $submission = $this->submission(status: DocumentSubmissionStatus::APPROVED);

        self::assertFalse($this->policy->canReview($application, $submission));
    }

    public function testAssertCanReviewThrowsWhenNotReviewable(): void
    {
        $application = $this->application(ApplicationStatus::PAYMENT_PENDING);
        $submission = $this->submission();

        $this->expectException(DomainRuleException::class);
        $this->policy->assertCanReview($application, $submission);
    }

    private function application(string $status): Application
    {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new Application(
            applicationId: 1,
            applicationNumber: 'APP-UNIT-002',
            userId: 10,
            courseVersionId: 5,
            batchId: 3,
            status: $status,
            stateVersion: 2,
            submittedAt: $now,
            declarationAcceptedVersion: '2026-07-22',
            declarationAcceptedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function submission(
        string $status = DocumentSubmissionStatus::UNDER_REVIEW,
        string $scanStatus = DocumentScanStatus::CLEAN,
        ?int $currentMarker = 1,
    ): DocumentSubmission {
        $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

        return new DocumentSubmission(
            documentSubmissionId: 42,
            applicationId: 1,
            requirementId: 10,
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
            supersededAt: $currentMarker === null ? $now : null,
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
}
