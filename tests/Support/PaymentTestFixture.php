<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Application\Review\ApplicationDecisionService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;

/**
 * Seeds an application in payment_pending via the real reviewer decision path.
 */
final class PaymentTestFixture
{
    /**
     * @param array<string, mixed> $options Forwarded to ReviewerTestFixture::seedUnderReviewApplication
     * @return array{
     *   application_id: int,
     *   state_version: int,
     *   requirement_ids: list<int>,
     *   submission_ids: list<int>,
     *   applicant_auth: AuthContext,
     *   applicant_user_id: int,
     *   applicant_auth_version: int,
     *   applicant_session: array{session: string, csrf: string},
     *   reviewer_auth: AuthContext,
     *   reviewer_user_id: int,
     *   reviewer_auth_version: int,
     *   reviewer_session: array{session: string, csrf: string},
     *   batch_id: int,
     *   course_version_id: int,
     *   course_id: int,
     *   finance_user_id: int,
     *   finance_auth_version: int,
     *   finance_session: array{session: string, csrf: string},
     *   finance_auth: AuthContext
     * }
     */
    public static function seedPaymentPendingApplication(array $options = []): array
    {
        $fixture = ReviewerTestFixture::seedUnderReviewApplication(
            requirementOverridesList: $options['requirement_overrides_list']
                ?? [['document_name' => 'Registration certificate', 'mandatory' => true]],
            options: $options,
        );

        $container = ApplicationFactory::container('testing');
        $container->get(ReviewerClaimService::class)->claim(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
        );

        $reviews = $container->get(DocumentReviewService::class);
        foreach ($fixture['submission_ids'] as $submissionId) {
            $reviews->verify(
                $fixture['reviewer_auth'],
                $fixture['application_id'],
                $submissionId,
            );
        }

        $approved = $container->get(ApplicationDecisionService::class)->approve(
            $fixture['reviewer_auth'],
            $fixture['application_id'],
            $fixture['state_version'],
        );

        self::assertSame(ApplicationStatus::PAYMENT_PENDING, $approved->status);

        $finance = DatabaseTestCase::financeFixture();
        $financeSession = DatabaseTestCase::bindSessionForUser(
            $finance['user_id'],
            $finance['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $financeAuth = AuthContext::authenticated(
            userId: $finance['user_id'],
            sessionId: 3,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $finance['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );

        return [
            'application_id' => $fixture['application_id'],
            'state_version' => $approved->stateVersion,
            'requirement_ids' => $fixture['requirement_ids'],
            'submission_ids' => $fixture['submission_ids'],
            'applicant_auth' => $fixture['applicant_auth'],
            'applicant_user_id' => $fixture['applicant_user_id'],
            'applicant_auth_version' => $fixture['applicant_auth_version'],
            'applicant_session' => $fixture['applicant_session'],
            'reviewer_auth' => $fixture['reviewer_auth'],
            'reviewer_user_id' => $fixture['reviewer_user_id'],
            'reviewer_auth_version' => $fixture['reviewer_auth_version'],
            'reviewer_session' => $fixture['reviewer_session'],
            'batch_id' => $fixture['batch_id'],
            'course_version_id' => $fixture['course_version_id'],
            'course_id' => $fixture['course_id'],
            'finance_user_id' => $finance['user_id'],
            'finance_auth_version' => $finance['auth_version'],
            'finance_session' => [
                'session' => $financeSession['session'],
                'csrf' => $financeSession['csrf'],
            ],
            'finance_auth' => $financeAuth,
        ];
    }

    /**
     * @param mixed $expected
     */
    private static function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                'PaymentTestFixture assertion failed: expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }
}
