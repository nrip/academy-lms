<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Credentials\DocumentDownloadService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use PHPUnit\Framework\TestCase;

final class ReviewerDownloadAuthorizationTest extends TestCase
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

    public function testReviewerInScopeCanDownload(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim($fixture['reviewer_auth'], $fixture['application_id']);

        $result = $container->get(DocumentDownloadService::class)->getReviewerSignedDownloadUrl(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['submission_ids'][0],
        );

        self::assertNotSame('', $result['url']);
    }

    public function testFinanceUserDenied(): void
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

        $downloads = ApplicationFactory::container('testing')->get(DocumentDownloadService::class);

        $this->expectException(AuthorizationException::class);
        $downloads->getReviewerSignedDownloadUrl(
            $financeAuth,
            $fixture['application_id'],
            $fixture['submission_ids'][0],
        );
    }

    public function testOutOfScopeReviewerDenied(): void
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

        $downloads = ApplicationFactory::container('testing')->get(DocumentDownloadService::class);

        $this->expectException(AuthorizationException::class);
        $downloads->getReviewerSignedDownloadUrl(
            $outsiderAuth,
            $fixture['application_id'],
            $fixture['submission_ids'][0],
        );
    }
}
