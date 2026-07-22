<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Credentials;

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Real multi-process races for document confirm and application submit.
 */
final class DocumentConcurrencyTest extends TestCase
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

    public function testConcurrentConfirmForSameRequirementYieldsOneCurrentRow(): void
    {
        $context = $this->seedDraftWithDualUploads();
        $worker = dirname(__DIR__, 2) . '/Support/document_confirm_worker.php';

        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                (string) $context['user_id'],
                (string) $context['auth_version'],
                (string) $context['application_id'],
                (string) $context['requirement_id'],
                $context['object_key_a'],
                $context['checksum_a'],
            ],
            [
                PHP_BINARY,
                $worker,
                (string) $context['user_id'],
                (string) $context['auth_version'],
                (string) $context['application_id'],
                (string) $context['requirement_id'],
                $context['object_key_b'],
                $context['checksum_b'],
            ],
        ]);

        $confirmed = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'confirmed:')));
        $conflicts = array_values(array_filter($results, static fn (string $r): bool => $r === 'conflict'));

        self::assertSame(2, count($confirmed) + count($conflicts));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM document_submissions
             WHERE application_id = ? AND requirement_id = ? AND current_marker = 1',
        );
        $stmt->execute([$context['application_id'], $context['requirement_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $history = $pdo->prepare(
            'SELECT COUNT(*) FROM document_submissions
             WHERE application_id = ? AND requirement_id = ?',
        );
        $history->execute([$context['application_id'], $context['requirement_id']]);
        self::assertGreaterThanOrEqual(1, (int) $history->fetchColumn());
    }

    public function testConcurrentSubmitYieldsExactlyOneUnderReviewTransition(): void
    {
        $context = $this->seedSubmittableApplication();
        $worker = dirname(__DIR__, 2) . '/Support/document_submit_worker.php';

        $results = $this->runWorkers([
            [
                PHP_BINARY,
                $worker,
                (string) $context['user_id'],
                (string) $context['auth_version'],
                (string) $context['application_id'],
            ],
            [
                PHP_BINARY,
                $worker,
                (string) $context['user_id'],
                (string) $context['auth_version'],
                (string) $context['application_id'],
            ],
        ]);

        $submitted = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'submitted:')));
        $conflicts = array_values(array_filter($results, static fn (string $r): bool => $r === 'conflict'));

        self::assertSame(1, count($submitted));
        self::assertSame(1, count($conflicts));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$context['application_id']]);
        self::assertSame(ApplicationStatus::UNDER_REVIEW, $stmt->fetchColumn());
    }

    /**
     * @return array{
     *   user_id: int,
     *   auth_version: int,
     *   application_id: int,
     *   requirement_id: int,
     *   object_key_a: string,
     *   checksum_a: string,
     *   object_key_b: string,
     *   checksum_b: string
     * }
     */
    private function seedDraftWithDualUploads(): array
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $user = DatabaseTestCase::applicantFixture();
        $auth = AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $container = ApplicationFactory::container('testing');
        $uploads = $container->get(DocumentUploadService::class);
        $application = $container->get(DraftApplicationService::class)->createDraft($auth, $seeded['batch_id']);
        $container->get(ApplicationDeclarationService::class)->acceptOnDraft($auth, $application->applicationId);

        $contentsA = str_repeat('A', 2048);
        $authA = $uploads->authorizeUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            'first.pdf',
            'application/pdf',
            strlen($contentsA),
        );
        $uploads->receiveLocalUpload($auth, $application->applicationId, $authA->authorizationId, $contentsA);

        $contentsB = str_repeat('B', 2048);
        $authB = $uploads->authorizeUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            'second.pdf',
            'application/pdf',
            strlen($contentsB),
        );
        $uploads->receiveLocalUpload($auth, $application->applicationId, $authB->authorizationId, $contentsB);

        return [
            'user_id' => $user['user_id'],
            'auth_version' => $user['auth_version'],
            'application_id' => $application->applicationId,
            'requirement_id' => $seeded['requirement_ids'][0],
            'object_key_a' => $authA->objectKey,
            'checksum_a' => hash('sha256', $contentsA),
            'object_key_b' => $authB->objectKey,
            'checksum_b' => hash('sha256', $contentsB),
        ];
    }

    /**
     * @return array{user_id: int, auth_version: int, application_id: int}
     */
    private function seedSubmittableApplication(): array
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $user = DatabaseTestCase::applicantFixture();
        $this->seedCompleteProfile($user['user_id']);

        $auth = AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $container = ApplicationFactory::container('testing');
        $uploads = $container->get(DocumentUploadService::class);
        $application = $container->get(DraftApplicationService::class)->createDraft($auth, $seeded['batch_id']);
        $container->get(ApplicationDeclarationService::class)->acceptOnDraft($auth, $application->applicationId);

        $contents = str_repeat('C', 2048);
        $authorization = $uploads->authorizeUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            'certificate.pdf',
            'application/pdf',
            strlen($contents),
        );
        $uploads->receiveLocalUpload($auth, $application->applicationId, $authorization->authorizationId, $contents);
        $uploads->confirmUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            $authorization->objectKey,
            hash('sha256', $contents),
        );
        $container->get(DocumentScanWorker::class)->run('phpunit-concurrency-submit');

        return [
            'user_id' => $user['user_id'],
            'auth_version' => $user['auth_version'],
            'application_id' => $application->applicationId,
        ];
    }

    private function seedCompleteProfile(int $userId): void
    {
        $pdo = DatabaseTestCase::pdo();
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
