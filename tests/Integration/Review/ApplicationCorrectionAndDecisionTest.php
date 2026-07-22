<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\Review\ApplicationCorrectionRequestService;
use Academy\Application\Review\ApplicationDecisionService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\LearnerCorrectionResubmitService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Exception\ConflictException;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ApplicationCorrectionAndDecisionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testCorrectionResubmitAndApproveWithoutPaymentRow(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $claims = $container->get(ReviewerClaimService::class);
        $corrections = $container->get(ApplicationCorrectionRequestService::class);
        $uploads = $container->get(DocumentUploadService::class);
        $resubmit = $container->get(LearnerCorrectionResubmitService::class);
        $reviews = $container->get(DocumentReviewService::class);
        $decisions = $container->get(ApplicationDecisionService::class);

        $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $corrected = $corrections->requestCorrection(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            [$fixture['requirement_ids'][0]],
            DocumentRejectionReasonCode::INCOMPLETE,
            'Please upload a complete certificate.',
            null,
            $fixture['state_version'],
        );
        self::assertSame(ApplicationStatus::RESUBMISSION_REQUESTED, $corrected->status);

        $requirementId = $fixture['requirement_ids'][0];
        $contents = str_repeat('R', 2048);
        $authorization = $uploads->authorizeUpload(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $requirementId,
            'corrected.pdf',
            'application/pdf',
            strlen($contents),
        );
        $uploads->receiveLocalUpload(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $authorization->authorizationId,
            $contents,
        );
        $uploads->confirmUpload(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $requirementId,
            $authorization->objectKey,
            hash('sha256', $contents),
        );
        $container->get(DocumentScanWorker::class)->run('reviewer-correction-scan');

        $resubmitted = $resubmit->resubmit(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $corrected->stateVersion,
        );
        self::assertSame(ApplicationStatus::UNDER_REVIEW, $resubmitted->status);

        $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $pdo = DatabaseTestCase::pdo();
        $currentSubmission = $pdo->prepare(
            'SELECT document_submission_id FROM document_submissions
             WHERE application_id = ? AND requirement_id = ? AND current_marker = 1',
        );
        $currentSubmission->execute([$fixture['application_id'], $requirementId]);
        $submissionId = (int) $currentSubmission->fetchColumn();

        $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);

        $approved = $decisions->approve(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $resubmitted->stateVersion,
        );
        self::assertSame(ApplicationStatus::PAYMENT_PENDING, $approved->status);
    }

    public function testRejectApplicationWithReason(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $reviews = $container->get(DocumentReviewService::class);
        foreach ($fixture['submission_ids'] as $submissionId) {
            $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);
        }

        $rejected = $container->get(ApplicationDecisionService::class)->reject(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            DocumentRejectionReasonCode::QUALIFICATION_INELIGIBLE,
            'Does not meet eligibility.',
            null,
            $fixture['state_version'],
        );

        self::assertSame(ApplicationStatus::REJECTED, $rejected->status);

        $pdo = DatabaseTestCase::pdo();
        $audit = $pdo->prepare(
            'SELECT COUNT(*) FROM verification_audit_log
             WHERE application_id = ? AND action = ?',
        );
        $audit->execute([$fixture['application_id'], 'application_rejected']);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testVerificationAuditLogIsImmutable(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $container->get(DocumentReviewService::class)->verify(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
        );

        $pdo = DatabaseTestCase::pdo();
        $auditId = (int) $pdo->query(
            'SELECT verification_audit_id FROM verification_audit_log
             WHERE application_id = ' . (int) $fixture['application_id'] . ' LIMIT 1',
        )->fetchColumn();

        $this->expectException(PDOException::class);
        $pdo->prepare('DELETE FROM verification_audit_log WHERE verification_audit_id = ?')
            ->execute([$auditId]);
    }

    public function testStaleStateVersionLeavesNoPartialDecision(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $reviews = $container->get(DocumentReviewService::class);
        foreach ($fixture['submission_ids'] as $submissionId) {
            $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);
        }

        $pdo = DatabaseTestCase::pdo();
        $beforeStatus = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $beforeStatus->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::UNDER_REVIEW, $beforeStatus->fetchColumn());

        try {
            $container->get(ApplicationDecisionService::class)->approve(
                $fixture['reviewer_auth'],
                $fixture['application_id'],
                $fixture['state_version'] - 1,
            );
            self::fail('Expected ConflictException');
        } catch (ConflictException) {
        }

        $afterStatus = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $afterStatus->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::UNDER_REVIEW, $afterStatus->fetchColumn());
    }
}
