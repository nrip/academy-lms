<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\Review\ApplicationCorrectionRequestService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use PHPUnit\Framework\TestCase;

final class ReviewerConcurrencyTest extends TestCase
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

    public function testConcurrentClaimYieldsExactlyOneWinner(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $secondReviewer = DatabaseTestCase::reviewerFixture();
        ReviewerTestFixture::assignReviewerScope(
            reviewerUserId: $secondReviewer['user_id'],
            scopeType: 'batch',
            courseId: $fixture['course_id'],
            courseVersionId: $fixture['course_version_id'],
            batchId: $fixture['batch_id'],
            createdByUserId: $secondReviewer['user_id'],
        );

        $worker = dirname(__DIR__, 2) . '/Support/reviewer_claim_worker.php';
        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['reviewer_user_id'],
                (string) $fixture['reviewer_auth_version'],
                (string) $fixture['application_id'],
            ],
            [
                PHP_BINARY,
                $worker,
                (string) $secondReviewer['user_id'],
                (string) $secondReviewer['auth_version'],
                (string) $fixture['application_id'],
            ],
        ]);

        $claimed = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'claimed:')));
        $conflicts = array_values(array_filter($results, static fn (string $r): bool => $r === 'conflict'));

        self::assertSame(1, count($claimed));
        self::assertSame(1, count($conflicts));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM application_review_assignments
             WHERE application_id = ? AND active_marker = 1',
        );
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testConcurrentApproveVsRejectYieldsOneDecision(): void
    {
        $fixture = $this->seedReadyForDecision();
        $worker = dirname(__DIR__, 2) . '/Support/reviewer_decide_worker.php';

        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['reviewer_user_id'],
                (string) $fixture['reviewer_auth_version'],
                (string) $fixture['application_id'],
                (string) $fixture['state_version'],
                'approve',
            ],
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['reviewer_user_id'],
                (string) $fixture['reviewer_auth_version'],
                (string) $fixture['application_id'],
                (string) $fixture['state_version'],
                'reject',
            ],
        ]);

        $decided = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'decided:')));
        $conflicts = array_values(array_filter($results, static fn (string $r): bool => $r === 'conflict'));

        self::assertSame(1, count($decided));
        self::assertSame(1, count($conflicts));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        $status = $stmt->fetchColumn();
        self::assertContains($status, [ApplicationStatus::PAYMENT_PENDING, ApplicationStatus::REJECTED]);
    }

    public function testConcurrentVerifyOnSameDocumentIsDeterministic(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $worker = dirname(__DIR__, 2) . '/Support/reviewer_verify_worker.php';
        $submissionId = $fixture['submission_ids'][0];

        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['reviewer_user_id'],
                (string) $fixture['reviewer_auth_version'],
                (string) $fixture['application_id'],
                (string) $submissionId,
            ],
            [
                PHP_BINARY,
                $worker,
                (string) $fixture['reviewer_user_id'],
                (string) $fixture['reviewer_auth_version'],
                (string) $fixture['application_id'],
                (string) $submissionId,
            ],
        ]);

        $verified = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'verified:')));
        self::assertSame(1, count($verified));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT status FROM document_submissions WHERE document_submission_id = ?',
        );
        $stmt->execute([$submissionId]);
        self::assertSame('approved', $stmt->fetchColumn());
    }

    public function testLearnerResubmitVsReviewerVerifyHasSingleOutcome(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $claims = $container->get(ReviewerClaimService::class);
        $corrections = $container->get(ApplicationCorrectionRequestService::class);
        $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $corrected = $corrections->requestCorrection(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            [$fixture['requirement_ids'][0]],
            DocumentRejectionReasonCode::INCOMPLETE,
            'Please re-upload.',
            null,
            $fixture['state_version'],
        );

        $uploads = $container->get(DocumentUploadService::class);
        $contents = str_repeat('L', 2048);
        $authorization = $uploads->authorizeUpload(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $fixture['requirement_ids'][0],
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
        $newSubmission = $uploads->confirmUpload(
            $fixture['applicant_auth'],
            $fixture['application_id'],
            $fixture['requirement_ids'][0],
            $authorization->objectKey,
            hash('sha256', $contents),
        );
        $container->get(DocumentScanWorker::class)->run('reviewer-concurrency-resubmit');

        $resubmitWorker = dirname(__DIR__, 2) . '/Support/learner_resubmit_worker.php';
        $verifyWorker = dirname(__DIR__, 2) . '/Support/reviewer_verify_worker.php';

        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $resubmitWorker,
                (string) $fixture['applicant_user_id'],
                (string) $fixture['applicant_auth_version'],
                (string) $fixture['application_id'],
                (string) $corrected->stateVersion,
            ],
            [
                PHP_BINARY,
                $verifyWorker,
                (string) $fixture['reviewer_user_id'],
                (string) $fixture['reviewer_auth_version'],
                (string) $fixture['application_id'],
                (string) $newSubmission->documentSubmissionId,
            ],
        ]);

        $successes = array_values(array_filter(
            $results,
            static fn (string $r): bool => str_starts_with($r, 'resubmitted:') || str_starts_with($r, 'verified:'),
        ));
        $conflicts = array_values(array_filter(
            $results,
            static fn (string $r): bool => $r === 'conflict' || str_starts_with($r, 'domain:'),
        ));

        self::assertGreaterThanOrEqual(1, count($successes));
        self::assertSame(2, count($successes) + count($conflicts));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertContains($stmt->fetchColumn(), [
            ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::RESUBMISSION_REQUESTED,
        ]);
    }

    /**
     * @return array{
     *   application_id: int,
     *   state_version: int,
     *   reviewer_user_id: int,
     *   reviewer_auth_version: int
     * }
     */
    private function seedReadyForDecision(): array
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $reviews = $container->get(DocumentReviewService::class);
        foreach ($fixture['submission_ids'] as $submissionId) {
            $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);
        }

        return [
            'application_id' => $fixture['application_id'],
            'state_version' => $fixture['state_version'],
            'reviewer_user_id' => $fixture['reviewer_user_id'],
            'reviewer_auth_version' => $fixture['reviewer_auth_version'],
        ];
    }

    /**
     * @param list<list<string>> $commands
     * @return list<string>
     */
    private function runWorkers(array $commands): array
    {
        $env = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
            'APP_ENV' => 'testing',
            'TOKEN_PEPPER' => $_ENV['TOKEN_PEPPER'] ?? 'testing-token-pepper-not-for-production',
            'OTP_PEPPER' => $_ENV['OTP_PEPPER'] ?? 'testing-otp-pepper-not-for-production',
            'RATE_LIMIT_PEPPER' => $_ENV['RATE_LIMIT_PEPPER'] ?? 'phpunit-rate-limit-pepper',
            'NOTIFICATION_DELIVERY_KEY' => $_ENV['NOTIFICATION_DELIVERY_KEY']
                ?? 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ];

        $processes = [];
        $pipesList = [];
        foreach ($commands as $command) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($command, $descriptors, $pipes, null, $env);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $processes[] = $proc;
            $pipesList[] = $pipes;
        }

        $results = [];
        foreach ($processes as $index => $proc) {
            $stdout = stream_get_contents($pipesList[$index][1]);
            $stderr = stream_get_contents($pipesList[$index][2]);
            fclose($pipesList[$index][1]);
            fclose($pipesList[$index][2]);
            $status = proc_close($proc);
            self::assertSame(0, $status, 'Worker failed: ' . $stderr . ' / ' . $stdout);
            $results[] = trim((string) $stdout);
        }

        return $results;
    }
}
