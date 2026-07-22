<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Review\ApplicationDecisionService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ReviewerVerificationSecurityTest extends TestCase
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

    public function testDecideFailsWithoutClaim(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $decisions = ApplicationFactory::container('testing')->get(ApplicationDecisionService::class);

        $this->expectException(ConflictException::class);
        $decisions->approve(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['state_version'],
        );
    }

    public function testApplicantForbiddenOnReviewerRoutes(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $boot = $fixture['applicant_session'];

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/reviewer/applications', 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFinanceSoDOnReviewerDownload(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $finance = DatabaseTestCase::financeFixture();
        $financeAuth = AuthContext::authenticated(
            userId: $finance['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $finance['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: \Academy\Domain\Identity\AccountStatus::ACTIVE,
        );

        $downloads = ApplicationFactory::container('testing')
            ->get(\Academy\Application\Credentials\DocumentDownloadService::class);

        $this->expectException(AuthorizationException::class);
        $downloads->getReviewerSignedDownloadUrl(
            $financeAuth,
            $fixture['application_id'],
            $fixture['submission_ids'][0],
        );
    }

    public function testHistoricalSubmissionCannotVerify(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $historicalId = $fixture['submission_ids'][0];
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare(
            'UPDATE document_submissions SET current_marker = NULL, status = ? WHERE document_submission_id = ?',
        )->execute(['superseded', $historicalId]);

        $this->expectException(NotFoundException::class);
        $container->get(DocumentReviewService::class)->verify(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $historicalId,
        );
    }

    public function testReasonSanitizationStripsScriptTags(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $container->get(DocumentReviewService::class)->reject(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
            DocumentRejectionReasonCode::OTHER,
            '<script>alert(1)</script>Please fix',
        );

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT learner_visible_message FROM document_submissions WHERE document_submission_id = ?',
        );
        $stmt->execute([$fixture['submission_ids'][0]]);
        $message = (string) $stmt->fetchColumn();

        self::assertStringNotContainsString('<script>', $message);
        self::assertStringContainsString('Please fix', $message);
    }

    public function testAuditPayloadPrivacyExcludesFilename(): void
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
        $stmt = $pdo->prepare(
            'SELECT new_value FROM audit_log
             WHERE action = ? AND affected_entity_type = ?
             ORDER BY audit_id DESC LIMIT 1',
        );
        $stmt->execute(['document.verified', 'document_submission']);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('display_filename', $payload);
        self::assertArrayNotHasKey('object_key', $payload);
    }

    public function testUnassignedReviewerCannotAccessOutOfScopeApplication(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $outsider = DatabaseTestCase::reviewerFixture();
        $outsiderAuth = AuthContext::authenticated(
            userId: $outsider['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $outsider['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: \Academy\Domain\Identity\AccountStatus::ACTIVE,
        );

        $this->expectException(AuthorizationException::class);
        ApplicationFactory::container('testing')->get(ApplicationDecisionService::class)->reject(
            $outsiderAuth,
            $fixture['application_id'],
            DocumentRejectionReasonCode::OTHER,
            null,
            null,
            $fixture['state_version'],
        );
    }
}
