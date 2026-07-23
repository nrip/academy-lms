<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Review;

use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Review\ApplicationReviewAssignmentStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\ReviewerTestFixture;
use PHPUnit\Framework\TestCase;

final class ReviewerClaimTest extends TestCase
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

    public function testClaimReleaseAndSecondClaimConflict(): void
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication();
        $claims = ApplicationFactory::container('testing')->get(ReviewerClaimService::class);

        $assignment = $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);
        self::assertSame(ApplicationReviewAssignmentStatus::ACTIVE, $assignment->assignmentStatus);

        $pdo = DatabaseTestCase::pdo();
        $activeCount = $pdo->prepare(
            'SELECT COUNT(*) FROM application_review_assignments
             WHERE application_id = ? AND active_marker = 1',
        );
        $activeCount->execute([$fixture['application_id']]);
        self::assertSame(1, (int) $activeCount->fetchColumn());

        $claims->release($fixture['reviewer_auth'], $fixture['application_id'], $assignment->rowVersion);

        $releasedCount = $pdo->prepare(
            'SELECT COUNT(*) FROM application_review_assignments
             WHERE application_id = ? AND active_marker = 1',
        );
        $releasedCount->execute([$fixture['application_id']]);
        self::assertSame(0, (int) $releasedCount->fetchColumn());

        $secondReviewer = DatabaseTestCase::reviewerFixture();
        ReviewerTestFixture::assignReviewerScope(
            reviewerUserId: $secondReviewer['user_id'],
            scopeType: 'batch',
            courseId: $fixture['course_id'],
            courseVersionId: $fixture['course_version_id'],
            batchId: $fixture['batch_id'],
            createdByUserId: $secondReviewer['user_id'],
        );
        $secondAuth = \Academy\Domain\Security\AuthContext::authenticated(
            userId: $secondReviewer['user_id'],
            sessionId: 3,
            authStage: \Academy\Domain\Identity\AuthStage::FULLY_AUTHENTICATED,
            authVersion: $secondReviewer['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: \Academy\Domain\Identity\AccountStatus::ACTIVE,
        );

        $claims->claim($secondAuth, $fixture['application_id']);

        $this->expectException(ConflictException::class);
        $claims->claim($fixture['reviewer_auth'], $fixture['application_id']);
    }
}
