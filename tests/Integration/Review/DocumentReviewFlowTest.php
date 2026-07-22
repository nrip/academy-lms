<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use PHPUnit\Framework\TestCase;

final class DocumentReviewFlowTest extends TestCase
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

    public function testVerifyRejectAndRequestResubmission(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $claims = $container->get(ReviewerClaimService::class);
        $reviews = $container->get(DocumentReviewService::class);

        $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $submissionId = $fixture['submission_ids'][0];

        $verified = $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);
        self::assertSame(DocumentSubmissionStatus::APPROVED, $verified->status);

        $pdo = DatabaseTestCase::pdo();
        $audit = $pdo->prepare(
            'SELECT COUNT(*) FROM verification_audit_log
             WHERE application_id = ? AND action = ?',
        );
        $audit->execute([$fixture['application_id'], 'verified']);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testRejectDocumentWithReasonCode(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $rejected = $container->get(DocumentReviewService::class)->reject(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
            DocumentRejectionReasonCode::BLURRY_ILLEGIBLE,
            'Please upload a clearer scan.',
        );

        self::assertSame(DocumentSubmissionStatus::REJECTED, $rejected->status);
        self::assertSame(DocumentRejectionReasonCode::BLURRY_ILLEGIBLE, $rejected->rejectionReasonCode);
    }

    public function testCannotReviewHistoricalSubmission(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $historicalId = $fixture['submission_ids'][0];
        $requirementId = $fixture['requirement_ids'][0];
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $pdo->prepare(
            'UPDATE document_submissions SET current_marker = NULL, status = ?, superseded_at = ? WHERE document_submission_id = ?',
        )->execute(['superseded', $now, $historicalId]);

        $pdo->prepare(
            'INSERT INTO document_submissions (
                application_id, requirement_id, object_key, display_filename, mime_type, size_bytes,
                checksum_sha256, status, scan_status, uploaded_by_user_id, submitted_at, superseded_at,
                current_marker, row_version, scan_attempt_count, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, 1, 1, ?, ?
            )',
        )->execute([
            $fixture['application_id'],
            $requirementId,
            'documents/replacement/key.pdf',
            'replacement.pdf',
            'application/pdf',
            2048,
            hash('sha256', 'replacement'),
            'under_review',
            'clean',
            $fixture['applicant_user_id'],
            $now,
            $now,
            $now,
        ]);

        $reviews = $container->get(DocumentReviewService::class);

        $this->expectException(NotFoundException::class);
        $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $historicalId);
    }

    public function testCannotReviewNonCleanDocument(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $applicant = DatabaseTestCase::applicantFixture();
        ReviewerTestFixture::seedCompleteProfile($applicant['user_id']);
        $auth = AuthContext::authenticated(
            userId: $applicant['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $applicant['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $container = ApplicationFactory::container('testing');
        $application = $container->get(DraftApplicationService::class)->createDraft($auth, $seeded['batch_id']);
        $container->get(ApplicationDeclarationService::class)->acceptOnDraft($auth, $application->applicationId);

        $uploads = $container->get(DocumentUploadService::class);
        $contents = str_repeat('P', 2048);
        $authorization = $uploads->authorizeUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            'pending.pdf',
            'application/pdf',
            strlen($contents),
        );
        $uploads->receiveLocalUpload($auth, $application->applicationId, $authorization->authorizationId, $contents);
        $submission = $uploads->confirmUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            $authorization->objectKey,
            hash('sha256', $contents),
        );

        $reviewer = DatabaseTestCase::reviewerFixture();
        ReviewerTestFixture::assignReviewerScope(
            reviewerUserId: $reviewer['user_id'],
            scopeType: 'batch',
            courseId: $seeded['course_id'],
            courseVersionId: $seeded['version_id'],
            batchId: $seeded['batch_id'],
            createdByUserId: $reviewer['user_id'],
        );
        $reviewerAuth = AuthContext::authenticated(
            userId: $reviewer['user_id'],
            sessionId: 2,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $reviewer['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );

        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare(
            'UPDATE applications SET status = ?, submitted_at = UTC_TIMESTAMP(6), state_version = 2 WHERE application_id = ?',
        )->execute(['under_review', $application->applicationId]);
        $pdo->prepare(
            'UPDATE document_submissions SET status = ? WHERE document_submission_id = ?',
        )->execute(['under_review', $submission->documentSubmissionId]);

        $container->get(ReviewerClaimService::class)->claim($reviewerAuth, $application->applicationId);

        $this->expectException(DomainRuleException::class);
        $container->get(DocumentReviewService::class)->verify(
            $reviewerAuth,
            $application->applicationId,
            $submission->documentSubmissionId,
        );
    }
}
