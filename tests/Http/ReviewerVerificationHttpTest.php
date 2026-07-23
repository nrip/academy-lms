<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ReviewerVerificationHttpTest extends TestCase
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

    public function testReviewerQueueAndDetailReturn200(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);

        $queue = $this->get('/reviewer/applications', $boot);
        self::assertSame(200, $queue->getStatusCode());

        $detail = $this->get('/reviewer/applications/' . $fixture['application_id'], $boot);
        self::assertSame(200, $detail->getStatusCode());
    }

    public function testClaimVerifyAndRejectDocumentFlow(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);
        $applicationId = $fixture['application_id'];
        $submissionId = $fixture['submission_ids'][0];

        $claim = $this->postJson('/reviewer/applications/' . $applicationId . '/claim', $boot, []);
        self::assertSame(200, $claim->getStatusCode());

        $verify = $this->postJson(
            '/reviewer/applications/' . $applicationId . '/documents/' . $submissionId . '/verify',
            $boot,
            [],
        );
        self::assertSame(200, $verify->getStatusCode());
    }

    public function testRejectDocumentViaHttp(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);
        $applicationId = $fixture['application_id'];
        $submissionId = $fixture['submission_ids'][0];

        $this->postJson('/reviewer/applications/' . $applicationId . '/claim', $boot, []);

        $rejectDoc = $this->postJson(
            '/reviewer/applications/' . $applicationId . '/documents/' . $submissionId . '/reject',
            $boot,
            [
                'reason_code' => DocumentRejectionReasonCode::WRONG_DOCUMENT,
                'learner_visible_message' => 'Wrong file uploaded.',
            ],
        );
        self::assertSame(200, $rejectDoc->getStatusCode());
    }

    public function testRequestCorrectionAndLearnerResubmit(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $reviewerBoot = $this->reviewerBoot($fixture);
        $applicantBoot = $this->applicantBoot($fixture);
        $applicationId = $fixture['application_id'];

        $this->postJson('/reviewer/applications/' . $applicationId . '/claim', $reviewerBoot, []);

        $correction = $this->postJson(
            '/reviewer/applications/' . $applicationId . '/request-correction',
            $reviewerBoot,
            [
                'requirement_ids' => [(string) $fixture['requirement_ids'][0]],
                'reason_code' => DocumentRejectionReasonCode::INCOMPLETE,
                'learner_visible_message' => 'Please re-upload.',
                'state_version' => (string) $fixture['state_version'],
            ],
        );
        self::assertSame(200, $correction->getStatusCode());

        $correctionsPage = $this->get('/applications/' . $applicationId . '/corrections', $applicantBoot);
        self::assertSame(200, $correctionsPage->getStatusCode());
    }

    public function testApproveWithoutAllVerifiedReturns422(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);

        $this->postJson('/reviewer/applications/' . $fixture['application_id'] . '/claim', $boot, []);

        $response = $this->postJson(
            '/reviewer/applications/' . $fixture['application_id'] . '/approve',
            $boot,
            ['state_version' => (string) $fixture['state_version']],
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testStaleStateVersionReturns409(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $reviews = $container->get(DocumentReviewService::class);
        foreach ($fixture['submission_ids'] as $submissionId) {
            $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);
        }

        $response = $this->postJson(
            '/reviewer/applications/' . $fixture['application_id'] . '/approve',
            $boot,
            ['state_version' => (string) ($fixture['state_version'] - 1)],
        );

        self::assertSame(409, $response->getStatusCode());
    }

    public function testMissingCsrfOnPostReturns403(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/reviewer/applications/' . $fixture['application_id'] . '/claim', 'POST'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testApplicantForbiddenOnReviewerRoutes(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->applicantBoot($fixture);

        $response = $this->get('/reviewer/applications', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testFinanceCannotDownloadReviewerDocument(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $finance = DatabaseTestCase::financeFixture();
        $boot = DatabaseTestCase::bindSessionForUser(
            $finance['user_id'],
            $finance['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/reviewer/applications/' . $fixture['application_id']
                    . '/documents/' . $fixture['submission_ids'][0] . '/download',
                'GET',
            ))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testNoGetMutationRoutesExist(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/reviewer/applications/' . $fixture['application_id'] . '/claim',
                'GET',
            ))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertContains($response->getStatusCode(), [404, 405]);
    }

    public function testRejectApplicationViaHttp(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $this->reviewerBoot($fixture);
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);
        $reviews = $container->get(DocumentReviewService::class);
        foreach ($fixture['submission_ids'] as $submissionId) {
            $reviews->verify($fixture['reviewer_auth'], $fixture['application_id'], $submissionId);
        }

        $response = $this->postJson(
            '/reviewer/applications/' . $fixture['application_id'] . '/reject',
            $boot,
            [
                'reason_code' => DocumentRejectionReasonCode::OTHER,
                'learner_visible_message' => 'Rejected.',
                'state_version' => (string) $fixture['state_version'],
            ],
        );

        self::assertSame(200, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$fixture['application_id']]);
        self::assertSame(ApplicationStatus::REJECTED, $stmt->fetchColumn());
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function get(string $path, array $boot): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, string> $body
     */
    private function postJson(string $path, array $boot, array $body): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
                ->withHeader('Accept', 'application/json')
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody($body + ['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array{session: string, csrf: string}
     */
    private function reviewerBoot(array $fixture): array
    {
        return $fixture['reviewer_session'];
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array{session: string, csrf: string}
     */
    private function applicantBoot(array $fixture): array
    {
        return $fixture['applicant_session'];
    }
}
