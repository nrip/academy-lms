<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\ApplicationSubmitService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Review\ReviewerScopeType;
use Academy\Domain\Security\AuthContext;
use PDO;

/**
 * Seeds a catalogue, applicant with complete profile, clean scanned documents,
 * submitted application (under_review), and a scoped credential reviewer.
 */
final class ReviewerTestFixture
{
    /**
     * @param list<array<string, mixed>> $requirementOverridesList
     * @param array<string, mixed> $options scope_type: batch|course_version, course_overrides, batch_overrides
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
     *   course_id: int
     * }
     */
    public static function seedUnderReviewApplication(
        array $requirementOverridesList = [['document_name' => 'Registration certificate', 'mandatory' => true]],
        array $options = [],
    ): array {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            courseOverrides: $options['course_overrides'] ?? [],
            batchOverrides: $options['batch_overrides'] ?? [],
            requirementOverridesList: $requirementOverridesList,
        );

        $applicant = DatabaseTestCase::applicantFixture();
        self::seedCompleteProfile($applicant['user_id']);

        $applicantAuth = AuthContext::authenticated(
            userId: $applicant['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $applicant['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $container = ApplicationFactory::container('testing');
        $drafts = $container->get(DraftApplicationService::class);
        $declarations = $container->get(ApplicationDeclarationService::class);
        $uploads = $container->get(DocumentUploadService::class);
        $scanWorker = $container->get(DocumentScanWorker::class);
        $submit = $container->get(ApplicationSubmitService::class);

        $application = $drafts->createDraft($applicantAuth, $seeded['batch_id']);
        $declarations->acceptOnDraft($applicantAuth, $application->applicationId);

        $submissionIds = [];
        foreach ($seeded['requirement_ids'] as $index => $requirementId) {
            $contents = str_repeat(chr(65 + ($index % 26)), 2048);
            $filename = 'certificate-' . ($index + 1) . '.pdf';
            $authorization = $uploads->authorizeUpload(
                $applicantAuth,
                $application->applicationId,
                $requirementId,
                $filename,
                'application/pdf',
                strlen($contents),
            );
            $uploads->receiveLocalUpload(
                $applicantAuth,
                $application->applicationId,
                $authorization->authorizationId,
                $contents,
            );
            $submission = $uploads->confirmUpload(
                $applicantAuth,
                $application->applicationId,
                $requirementId,
                $authorization->objectKey,
                hash('sha256', $contents),
            );
            $submissionIds[] = $submission->documentSubmissionId;
        }

        $scanWorker->run('reviewer-fixture-scan');
        $submitted = $submit->submit($applicantAuth, $application->applicationId);

        self::assertSame(ApplicationStatus::UNDER_REVIEW, $submitted->status);

        $reviewer = DatabaseTestCase::reviewerFixture();
        $scopeType = (string) ($options['scope_type'] ?? ReviewerScopeType::BATCH);
        self::assignReviewerScope(
            reviewerUserId: $reviewer['user_id'],
            scopeType: $scopeType,
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

        $applicantSession = DatabaseTestCase::bindSessionForUser(
            $applicant['user_id'],
            $applicant['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $reviewerSession = DatabaseTestCase::bindSessionForUser(
            $reviewer['user_id'],
            $reviewer['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        return [
            'application_id' => $application->applicationId,
            'state_version' => $submitted->stateVersion,
            'requirement_ids' => $seeded['requirement_ids'],
            'submission_ids' => $submissionIds,
            'applicant_auth' => $applicantAuth,
            'applicant_user_id' => $applicant['user_id'],
            'applicant_auth_version' => $applicant['auth_version'],
            'applicant_session' => [
                'session' => $applicantSession['session'],
                'csrf' => $applicantSession['csrf'],
            ],
            'reviewer_auth' => $reviewerAuth,
            'reviewer_user_id' => $reviewer['user_id'],
            'reviewer_auth_version' => $reviewer['auth_version'],
            'reviewer_session' => [
                'session' => $reviewerSession['session'],
                'csrf' => $reviewerSession['csrf'],
            ],
            'batch_id' => $seeded['batch_id'],
            'course_version_id' => $seeded['version_id'],
            'course_id' => $seeded['course_id'],
        ];
    }

    public static function assignReviewerScope(
        int $reviewerUserId,
        string $scopeType,
        int $courseId,
        int $courseVersionId,
        int $batchId,
        int $createdByUserId,
    ): int {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $courseIdParam = null;
        $versionIdParam = null;
        $batchIdParam = null;

        if ($scopeType === ReviewerScopeType::BATCH) {
            $batchIdParam = $batchId;
        } elseif ($scopeType === ReviewerScopeType::COURSE_VERSION) {
            $versionIdParam = $courseVersionId;
        } elseif ($scopeType === ReviewerScopeType::COURSE) {
            $courseIdParam = $courseId;
        } else {
            throw new \InvalidArgumentException('Unsupported scope type: ' . $scopeType);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO reviewer_scope_assignments (
                reviewer_user_id, scope_type, course_id, course_version_id, batch_id,
                include_future_versions, effective_from, effective_to, revoked_at, revoked_reason,
                created_by_user_id, revoked_by_user_id, created_at, updated_at
            ) VALUES (
                :reviewer_user_id, :scope_type, :course_id, :course_version_id, :batch_id,
                0, :effective_from, NULL, NULL, NULL,
                :created_by_user_id, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'reviewer_user_id' => $reviewerUserId,
            'scope_type' => $scopeType,
            'course_id' => $courseIdParam,
            'course_version_id' => $versionIdParam,
            'batch_id' => $batchIdParam,
            'effective_from' => $now,
            'created_by_user_id' => $createdByUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function seedCompleteProfile(int $userId): void
    {
        $pdo = DatabaseTestCase::pdo();
        $existing = $pdo->prepare('SELECT learner_profile_id FROM learner_profiles WHERE user_id = :user_id');
        $existing->execute(['user_id' => $userId]);
        if ($existing->fetch(PDO::FETCH_ASSOC) !== false) {
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $pdo->prepare(
            'INSERT INTO learner_profiles (
                user_id, first_name, last_name, preferred_display_name, certificate_name,
                certificate_name_confirmed, date_of_birth, address_line_1, city, state, postal_code,
                country, profession, current_designation, organization_name, years_of_experience,
                speciality, medical_council_name, medical_council_registration_number,
                medical_council_registration_state, registration_valid_from, registration_valid_until,
                row_version, created_at, updated_at
            ) VALUES (
                :user_id, :first_name, :last_name, :preferred_display_name, :certificate_name,
                1, :date_of_birth, :address_line_1, :city, :state, :postal_code,
                :country, :profession, :current_designation, :organization_name, :years_of_experience,
                :speciality, :medical_council_name, :medical_council_registration_number,
                :medical_council_registration_state, :registration_valid_from, :registration_valid_until,
                1, :created_at, :updated_at
            )',
        )->execute([
            'user_id' => $userId,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'preferred_display_name' => 'Dr Rao',
            'certificate_name' => 'Dr Asha Rao',
            'date_of_birth' => '1990-01-01',
            'address_line_1' => '1 Road',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'postal_code' => '560001',
            'country' => 'India',
            'profession' => 'Physician',
            'current_designation' => 'Consultant',
            'organization_name' => 'City Hospital',
            'years_of_experience' => 10,
            'speciality' => 'Endocrinology',
            'medical_council_name' => 'NMC',
            'medical_council_registration_number' => 'REG123',
            'medical_council_registration_state' => 'Karnataka',
            'registration_valid_from' => '2020-01-01',
            'registration_valid_until' => '2030-01-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $learnerProfileId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO learner_qualifications (
                learner_profile_id, qualification_type, qualification_name, institution_name,
                completion_year, display_order, row_version, created_at, updated_at
            ) VALUES (
                :learner_profile_id, :qualification_type, :qualification_name, :institution_name,
                :completion_year, 1, 1, :created_at, :updated_at
            )',
        )->execute([
            'learner_profile_id' => $learnerProfileId,
            'qualification_type' => 'Degree',
            'qualification_name' => 'MBBS',
            'institution_name' => 'AIIMS',
            'completion_year' => 2010,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param mixed $expected
     */
    private static function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                'ReviewerTestFixture assertion failed: expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }
}
