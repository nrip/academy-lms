<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerApplicationQueryService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Credentials\DocumentScanStatus;
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
 * Browser-uploaded documents must appear on the exact reviewer application.
 */
final class ReviewerDocumentVisibilityTest extends TestCase
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
        putenv('DOCUMENTS_STORAGE_DRIVER=local');
        $_ENV['DOCUMENTS_STORAGE_DRIVER'] = 'local';
        putenv('DOCUMENTS_FAKE_SCANNER=1');
        $_ENV['DOCUMENTS_FAKE_SCANNER'] = '1';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testBrowserUploadedDocumentsAppearOnExactReviewerApplication(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [
                ['document_name' => 'Medical council registration certificate', 'mandatory' => true],
                ['document_name' => 'Government-issued photo ID', 'mandatory' => true],
            ],
        );

        $detail = ApplicationFactory::container('testing')
            ->get(ReviewerApplicationQueryService::class)
            ->detail($fixture['reviewer_auth'], $fixture['application_id']);

        self::assertSame($fixture['application_id'], $detail->application->applicationId);
        self::assertCount(2, $detail->documentChecklist);
        foreach ($detail->documentChecklist as $item) {
            self::assertNotNull($item->documentSubmissionId);
            self::assertNotNull($item->displayFilename);
            self::assertContains($item->documentSubmissionId, $fixture['submission_ids']);
        }

        $html = (string) $this->get(
            '/reviewer/applications/' . $fixture['application_id'],
            $fixture['reviewer_session'],
        )->getBody();
        self::assertStringContainsString($detail->application->applicationNumber, $html);
        self::assertStringNotContainsString('No current submission.', $html);
        self::assertStringContainsString('Medical council registration certificate', $html);
        self::assertStringContainsString('Government-issued photo ID', $html);
    }

    public function testScanPendingDocumentsVisibleButNotApprovable(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare(
            'UPDATE document_submissions
             SET status = :status, scan_status = :scan_status
             WHERE document_submission_id = :id',
        )->execute([
            'status' => DocumentSubmissionStatus::UPLOADED,
            'scan_status' => DocumentScanStatus::PENDING,
            'id' => $fixture['submission_ids'][0],
        ]);

        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $detail = $container->get(ReviewerApplicationQueryService::class)
            ->detail($fixture['reviewer_auth'], $fixture['application_id']);

        $item = $detail->documentChecklist[0];
        self::assertSame(DocumentSubmissionStatus::UPLOADED, $item->status);
        self::assertSame(DocumentScanStatus::PENDING, $item->scanStatus);
        self::assertNotNull($item->documentSubmissionId);

        $html = (string) $this->get(
            '/reviewer/applications/' . $fixture['application_id'],
            $fixture['reviewer_session'],
        )->getBody();
        self::assertStringNotContainsString('No current submission.', $html);
        self::assertStringContainsString('scan: pending', $html);
        self::assertStringNotContainsString('>Verify<', $html);
    }

    public function testAfterScanCleanDocumentsBecomeReviewable(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare(
            'UPDATE document_submissions
             SET status = :status, scan_status = :scan_status, scan_queued_at = UTC_TIMESTAMP(6)
             WHERE document_submission_id = :id',
        )->execute([
            'status' => DocumentSubmissionStatus::UPLOADED,
            'scan_status' => DocumentScanStatus::PENDING,
            'id' => $fixture['submission_ids'][0],
        ]);

        ApplicationFactory::container('testing')->get(DocumentScanWorker::class)->run('visibility-scan');

        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $verified = $container->get(DocumentReviewService::class)->verify(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
        );
        self::assertSame(DocumentSubmissionStatus::APPROVED, $verified->status);
    }

    public function testOtherApplicationSubmissionNeverDisplayed(): void
    {
        $first = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [['document_name' => 'Registration A', 'mandatory' => true]],
        );
        $second = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [['document_name' => 'Registration B', 'mandatory' => true]],
            options: [
                'catalogue' => DatabaseTestCase::seedPublishedCatalogueWithRequirements(
                    courseOverrides: ['course_code' => 'VIS-B-' . bin2hex(random_bytes(3))],
                    requirementOverridesList: [['document_name' => 'Registration B', 'mandatory' => true]],
                ),
            ],
        );

        // Re-scope second reviewer is different; use first reviewer on first app only.
        $detail = ApplicationFactory::container('testing')
            ->get(ReviewerApplicationQueryService::class)
            ->detail($first['reviewer_auth'], $first['application_id']);

        $ids = array_map(
            static fn ($item) => $item->documentSubmissionId,
            $detail->documentChecklist,
        );
        self::assertNotContains($second['submission_ids'][0], $ids);
        self::assertContains($first['submission_ids'][0], $ids);
    }

    public function testSupersededSubmissionNotShownAsCurrent(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $pdo = DatabaseTestCase::pdo();
        $oldId = $fixture['submission_ids'][0];
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $pdo->prepare(
            'UPDATE document_submissions SET current_marker = NULL, status = :status WHERE document_submission_id = :id',
        )->execute([
            'status' => DocumentSubmissionStatus::SUPERSEDED,
            'id' => $oldId,
        ]);

        $contents = str_repeat('N', 2048);
        $pdo->prepare(
            'INSERT INTO document_submissions (
                application_id, requirement_id, uploaded_by_user_id, object_key, display_filename,
                mime_type, size_bytes, checksum_sha256, status, scan_status, current_marker,
                submitted_at, created_at, updated_at
            ) VALUES (
                :application_id, :requirement_id, :uploaded_by_user_id, :object_key, :display_filename,
                :mime_type, :size_bytes, :checksum_sha256, :status, :scan_status, 1,
                :submitted_at, :created_at, :updated_at
            )',
        )->execute([
            'application_id' => $fixture['application_id'],
            'requirement_id' => $fixture['requirement_ids'][0],
            'uploaded_by_user_id' => $fixture['applicant_user_id'],
            'object_key' => 'documents/replacement-' . $fixture['application_id'] . '.pdf',
            'display_filename' => 'replacement.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'status' => DocumentSubmissionStatus::UNDER_REVIEW,
            'scan_status' => DocumentScanStatus::CLEAN,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $newId = (int) $pdo->lastInsertId();

        $detail = ApplicationFactory::container('testing')
            ->get(ReviewerApplicationQueryService::class)
            ->detail($fixture['reviewer_auth'], $fixture['application_id']);
        self::assertSame($newId, $detail->documentChecklist[0]->documentSubmissionId);
        self::assertNotSame($oldId, $detail->documentChecklist[0]->documentSubmissionId);
    }

    public function testQueueContainsSubmittedApplicationAndMatchesDetailReference(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $queue = ApplicationFactory::container('testing')->get(ReviewerQueueQuery::class)
            ->listForReviewer(
                $fixture['reviewer_user_id'],
                ReviewerQueueFilter::UNDER_REVIEW,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

        $numbers = array_map(static fn ($item) => $item->applicationNumber, $queue);
        self::assertContains(
            ApplicationFactory::container('testing')
                ->get(ReviewerApplicationQueryService::class)
                ->detail($fixture['reviewer_auth'], $fixture['application_id'])
                ->application->applicationNumber,
            $numbers,
        );

        $match = null;
        foreach ($queue as $item) {
            if ($item->applicationId === $fixture['application_id']) {
                $match = $item;
                break;
            }
        }
        self::assertNotNull($match);
        self::assertNotSame('', $match->learnerDisplayName);

        $html = (string) $this->get(
            '/reviewer/applications?filter=under_review',
            $fixture['reviewer_session'],
        )->getBody();
        self::assertStringContainsString($match->applicationNumber, $html);
        self::assertStringContainsString($match->learnerDisplayName, $html);
    }

    public function testSeededAndLiveApplicationsRemainIsolated(): void
    {
        $live = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [['document_name' => 'Live cert', 'mandatory' => true]],
        );
        $seeded = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: [['document_name' => 'Seeded cert', 'mandatory' => true]],
            options: [
                'catalogue' => DatabaseTestCase::seedPublishedCatalogueWithRequirements(
                    courseOverrides: ['course_code' => 'SEED-' . bin2hex(random_bytes(3))],
                    requirementOverridesList: [['document_name' => 'Seeded cert', 'mandatory' => true]],
                ),
            ],
        );

        $liveDetail = ApplicationFactory::container('testing')
            ->get(ReviewerApplicationQueryService::class)
            ->detail($live['reviewer_auth'], $live['application_id']);
        $seededDetail = ApplicationFactory::container('testing')
            ->get(ReviewerApplicationQueryService::class)
            ->detail($seeded['reviewer_auth'], $seeded['application_id']);

        self::assertNotSame($liveDetail->application->applicationNumber, $seededDetail->application->applicationNumber);
        self::assertSame($live['submission_ids'][0], $liveDetail->documentChecklist[0]->documentSubmissionId);
        self::assertSame($seeded['submission_ids'][0], $seededDetail->documentChecklist[0]->documentSubmissionId);
        self::assertStringContainsString('Live cert', $liveDetail->documentChecklist[0]->documentName);
        self::assertStringContainsString('Seeded cert', $seededDetail->documentChecklist[0]->documentName);
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
