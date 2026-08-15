<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Review\ReviewerQueueFilter;
use Academy\Domain\Review\ReviewerQueueQuery;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * ready_for_decision reviewer queue filter — schema field + readiness semantics.
 */
final class ReviewerQueueReadyForDecisionTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;

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

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testReadyForDecisionHttpFilterReturns200(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);

        $response = $this->get('/reviewer/applications?filter=ready_for_decision', $boot);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Unknown column', (string) $response->getBody());
        self::assertStringNotContainsString('cdr.mandatory', (string) $response->getBody());
    }

    public function testOtherReviewerFiltersReturn200(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);

        foreach (ReviewerQueueFilter::ALL as $filter) {
            $response = $this->get('/reviewer/applications?filter=' . $filter, $boot);
            self::assertSame(200, $response->getStatusCode(), 'filter=' . $filter);
        }
    }

    public function testAllMandatoryVerifiedIsIncluded(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $this->verifyAll($fixture);

        $ids = $this->readyIds($fixture['reviewer_user_id']);
        self::assertContains($fixture['application_id'], $ids);
        self::assertCount(1, array_filter($ids, static fn (int $id): bool => $id === $fixture['application_id']));
    }

    public function testMissingMandatoryIsExcluded(): void
    {
        $catalogue = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [
                ['document_name' => 'Registration certificate', 'mandatory' => true],
                ['document_name' => 'Optional cover letter', 'mandatory' => false],
            ],
        );
        // Seed only uploads the listed requirements in fixture — build under_review with both,
        // then remove the mandatory current row to simulate missing.
        $fixture = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [
                ['document_name' => 'Registration certificate', 'mandatory' => true],
                ['document_name' => 'Optional cover letter', 'mandatory' => false],
            ],
            options: ['catalogue' => $catalogue],
        );

        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare(
            'UPDATE document_submissions SET current_marker = NULL, status = :status
             WHERE application_id = :application_id AND requirement_id = :requirement_id',
        )->execute([
            'status' => DocumentSubmissionStatus::SUPERSEDED,
            'application_id' => $fixture['application_id'],
            'requirement_id' => $fixture['requirement_ids'][0],
        ]);

        self::assertNotContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testPendingMandatoryIsExcluded(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        // Submitted docs are under_review (not approved) — not ready.
        self::assertNotContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testRejectedMandatoryIsExcluded(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $container->get(DocumentReviewService::class)->reject(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
            DocumentRejectionReasonCode::WRONG_DOCUMENT,
            'Wrong file.',
        );

        self::assertNotContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testCorrectionRequiredMandatoryIsExcluded(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $container->get(DocumentReviewService::class)->requestResubmission(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
            DocumentRejectionReasonCode::INCOMPLETE,
            'Please re-upload.',
        );

        self::assertNotContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testMissingOptionalDoesNotExclude(): void
    {
        $catalogue = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [
                ['document_name' => 'Registration certificate', 'mandatory' => true],
                ['document_name' => 'Optional cover letter', 'mandatory' => false],
            ],
        );
        $fixture = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [
                ['document_name' => 'Registration certificate', 'mandatory' => true],
                ['document_name' => 'Optional cover letter', 'mandatory' => false],
            ],
            options: ['catalogue' => $catalogue],
        );

        // Drop current optional submission only.
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare(
            'UPDATE document_submissions SET current_marker = NULL, status = :status
             WHERE application_id = :application_id AND requirement_id = :requirement_id',
        )->execute([
            'status' => DocumentSubmissionStatus::SUPERSEDED,
            'application_id' => $fixture['application_id'],
            'requirement_id' => $fixture['requirement_ids'][1],
        ]);

        $this->verifySubmission($fixture, $fixture['submission_ids'][0]);
        self::assertContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testSupersededRowDoesNotAffectReadiness(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $pdo = DatabaseTestCase::pdo();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        // Historical rejected row (not current) must be ignored once a clean approved current exists.
        $pdo->prepare(
            'INSERT INTO document_submissions (
                application_id, requirement_id, uploaded_by_user_id, object_key, display_filename,
                mime_type, size_bytes, checksum_sha256, status, scan_status, current_marker,
                rejection_reason_code, learner_visible_message, reviewed_by_user_id, reviewed_at,
                submitted_at, created_at, updated_at
            ) VALUES (
                :application_id, :requirement_id, :uploaded_by_user_id, :object_key, :display_filename,
                :mime_type, :size_bytes, :checksum_sha256, :status, :scan_status, NULL,
                :rejection_reason_code, :learner_visible_message, NULL, NULL,
                :submitted_at, :created_at, :updated_at
            )',
        )->execute([
            'application_id' => $fixture['application_id'],
            'requirement_id' => $fixture['requirement_ids'][0],
            'uploaded_by_user_id' => $fixture['applicant_user_id'],
            'object_key' => 'documents/historical-rejected-' . $fixture['application_id'] . '.pdf',
            'display_filename' => 'old.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'checksum_sha256' => hash('sha256', 'old'),
            'status' => DocumentSubmissionStatus::REJECTED,
            'scan_status' => 'clean',
            'rejection_reason_code' => DocumentRejectionReasonCode::WRONG_DOCUMENT,
            'learner_visible_message' => 'Old rejection',
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->verifyAll($fixture);
        self::assertContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testZeroMandatoryRequirementsIsReady(): void
    {
        $catalogue = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [
                ['document_name' => 'Optional only', 'mandatory' => false],
            ],
        );
        // Cannot submit without mandatory docs via normal path if submit requires mandatory —
        // seed application under_review directly after optional upload if submit allows.
        $fixture = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [
                ['document_name' => 'Optional only', 'mandatory' => false],
            ],
            options: ['catalogue' => $catalogue],
        );

        self::assertContains($fixture['application_id'], $this->readyIds($fixture['reviewer_user_id']));
    }

    public function testQuerySourceUsesMandatoryFlagNotMandatory(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Infrastructure/Review/PdoReviewerQueueQuery.php',
        );
        self::assertStringNotContainsString('cdr.mandatory =', $source);
        self::assertStringNotContainsString('cdr.mandatory ', $source);
        self::assertStringContainsString('cdr.mandatory_flag', $source);
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array{session: string, csrf: string}
     */
    private function reviewerBoot(array $fixture): array
    {
        return [
            'session' => $fixture['reviewer_session']['session'],
            'csrf' => $fixture['reviewer_session']['csrf'],
        ];
    }

    /**
     * @return list<int>
     */
    private function readyIds(int $reviewerUserId): array
    {
        $query = ApplicationFactory::container('testing')->get(ReviewerQueueQuery::class);
        $items = $query->listForReviewer(
            $reviewerUserId,
            ReviewerQueueFilter::READY_FOR_DECISION,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return array_map(static fn ($item): int => $item->applicationId, $items);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function verifyAll(array $fixture): void
    {
        foreach ($fixture['submission_ids'] as $submissionId) {
            $this->verifySubmission($fixture, (int) $submissionId);
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function verifySubmission(array $fixture, int $submissionId): void
    {
        $container = ApplicationFactory::container('testing');
        $claims = $container->get(ReviewerClaimService::class);
        try {
            $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);
        } catch (\Throwable) {
            // already claimed
        }
        $container->get(DocumentReviewService::class)->verify(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $submissionId,
        );
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function get(string $path, array $boot): ResponseInterface
    {
        $parts = parse_url('http://localhost' . $path);
        $uriPath = is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';
        $query = [];
        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $uriPath, 'GET'))
                ->withQueryParams($query)
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
