<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Domain\Admissions\ApplicationStatus;
use RuntimeException;

/**
 * Environment-gated WP-07 demo seeding helpers for local/testing/ci only.
 * Wraps existing PaymentTestFixture / ReviewerTestFixture paths — does not invent states.
 */
final class Wp07DemoFixture
{
    private static function assertSafeEnv(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
        if (!in_array($env, ['local', 'testing', 'ci'], true)) {
            throw new RuntimeException(
                'Wp07DemoFixture must never run in staging/production (APP_ENV=' . $env . ').',
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function seedPaymentPending(array $options = []): array
    {
        self::assertSafeEnv();

        return PaymentTestFixture::seedPaymentPendingApplication($options);
    }

    /**
     * @param list<array<string, mixed>> $requirementOverridesList
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function seedUnderReview(
        array $requirementOverridesList = [['document_name' => 'Registration certificate', 'mandatory' => true]],
        array $options = [],
    ): array {
        self::assertSafeEnv();
        $fixture = ReviewerTestFixture::seedUnderReviewApplication($requirementOverridesList, $options);
        self::assertApplicationStatus($fixture['application_id'], ApplicationStatus::UNDER_REVIEW);

        return $fixture;
    }

    /**
     * Draft via the real createDraft path (same catalogue/profile setup as under-review fixture,
     * without submit).
     *
     * @param array<string, mixed> $options
     * @return array{
     *   application_id: int,
     *   applicant_auth: \Academy\Domain\Security\AuthContext,
     *   applicant_user_id: int,
     *   applicant_session: array{session: string, csrf: string},
     *   batch_id: int,
     *   course_version_id: int,
     *   course_id: int
     * }
     */
    public static function seedDraft(array $options = []): array
    {
        self::assertSafeEnv();

        $catalogue = $options['catalogue'] ?? DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            courseOverrides: $options['course_overrides'] ?? [],
            batchOverrides: $options['batch_overrides'] ?? [],
            requirementOverridesList: $options['requirement_overrides_list']
                ?? [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );

        $applicant = DatabaseTestCase::applicantFixture();
        ReviewerTestFixture::seedCompleteProfile($applicant['user_id']);

        $applicantAuth = \Academy\Domain\Security\AuthContext::authenticated(
            userId: $applicant['user_id'],
            sessionId: 1,
            authStage: \Academy\Domain\Identity\AuthStage::FULLY_AUTHENTICATED,
            authVersion: $applicant['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: \Academy\Domain\Identity\AccountStatus::ACTIVE,
        );

        $application = ApplicationFactory::container('testing')
            ->get(\Academy\Application\Admissions\DraftApplicationService::class)
            ->createDraft($applicantAuth, $catalogue['batch_id']);

        self::assertApplicationStatus($application->applicationId, ApplicationStatus::DRAFT);

        $session = DatabaseTestCase::bindSessionForUser(
            $applicant['user_id'],
            $applicant['auth_version'],
            \Academy\Domain\Identity\AuthStage::FULLY_AUTHENTICATED,
        );

        return [
            'application_id' => $application->applicationId,
            'applicant_auth' => $applicantAuth,
            'applicant_user_id' => $applicant['user_id'],
            'applicant_session' => [
                'session' => $session['session'],
                'csrf' => $session['csrf'],
            ],
            'batch_id' => $catalogue['batch_id'],
            'course_version_id' => $catalogue['version_id'],
            'course_id' => $catalogue['course_id'],
        ];
    }

    private static function assertApplicationStatus(int $applicationId, string $expected): void
    {
        $stmt = DatabaseTestCase::pdo()->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $actual = (string) $stmt->fetchColumn();
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Wp07DemoFixture expected status %s, got %s for application %d',
                $expected,
                $actual,
                $applicationId,
            ));
        }
    }
}
