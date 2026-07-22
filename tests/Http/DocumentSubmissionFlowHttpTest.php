<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end learner journey through the real HTTP routes: draft -> accept
 * declaration -> authorize upload -> local "S3 PUT" -> confirm -> blocked
 * submit (scan pending) -> DocumentScanWorker -> submit succeeds. Exercises
 * every WP-03 route wired in config/container.php, not just the services
 * directly.
 */
final class DocumentSubmissionFlowHttpTest extends TestCase
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

    public function testFullSubmissionFlowSucceedsAfterCleanScan(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $requirementId = $seeded['requirement_ids'][0];

        $boot = $this->bootApplicant();
        $this->seedCompleteProfile($boot['user_id']);

        $applicationId = $this->createDraftApplication($boot, $seeded['batch_id']);

        $declarationResponse = $this->post('/applications/' . $applicationId, $boot, []);
        self::assertSame(303, $declarationResponse->getStatusCode());

        $authorizeResponse = $this->post('/applications/' . $applicationId . '/documents/upload-authorizations', $boot, [
            'requirement_id' => (string) $requirementId,
            'filename' => 'certificate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => '2048',
        ]);
        self::assertSame(201, $authorizeResponse->getStatusCode());
        $authorization = json_decode((string) $authorizeResponse->getBody(), true);
        self::assertIsArray($authorization);

        $fileContents = str_repeat('A', 2048);
        $localUploadPath = $authorization['upload_url'];
        $uploadResponse = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $localUploadPath, 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withBody($this->bodyStream($fileContents))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(200, $uploadResponse->getStatusCode());

        $confirmResponse = $this->post('/applications/' . $applicationId . '/documents/confirm', $boot, [
            'requirement_id' => (string) $requirementId,
            'object_key' => $authorization['object_key'],
            'checksum_sha256' => hash('sha256', $fileContents),
        ]);
        self::assertSame(201, $confirmResponse->getStatusCode());
        $confirmed = json_decode((string) $confirmResponse->getBody(), true);
        self::assertSame('uploaded', $confirmed['status']);
        self::assertSame('pending', $confirmed['scan_status']);

        // Scan still pending: submit must fail with the documented precondition blocker.
        $blockedSubmit = $this->post('/applications/' . $applicationId . '/submit', $boot, []);
        self::assertSame(303, $blockedSubmit->getStatusCode());
        self::assertStringContainsString('ok=0', $blockedSubmit->getHeaderLine('Location'));

        $processed = ApplicationFactory::container('testing')->get(DocumentScanWorker::class)->run('phpunit-worker');
        self::assertGreaterThanOrEqual(1, $processed);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status, scan_status FROM document_submissions WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('under_review', $row['status']);
        self::assertSame('clean', $row['scan_status']);

        $submitResponse = $this->post('/applications/' . $applicationId . '/submit', $boot, []);
        self::assertSame(303, $submitResponse->getStatusCode());
        self::assertStringContainsString('ok=1', $submitResponse->getHeaderLine('Location'));

        $appStmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $appStmt->execute([$applicationId]);
        self::assertSame('under_review', $appStmt->fetchColumn());
    }

    public function testSubmitBlockedWhenMandatoryDocumentMissing(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );

        $boot = $this->bootApplicant();
        $this->seedCompleteProfile($boot['user_id']);

        $applicationId = $this->createDraftApplication($boot, $seeded['batch_id']);
        $this->post('/applications/' . $applicationId, $boot, []);

        $response = $this->post('/applications/' . $applicationId . '/submit', $boot, []);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('ok=0', $response->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        self::assertSame('draft', $stmt->fetchColumn());
    }

    public function testMalwareFlaggedUploadNeverReachesUnderReview(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $requirementId = $seeded['requirement_ids'][0];

        $boot = $this->bootApplicant();
        $this->seedCompleteProfile($boot['user_id']);
        $applicationId = $this->createDraftApplication($boot, $seeded['batch_id']);
        $this->post('/applications/' . $applicationId, $boot, []);

        $authorizeResponse = $this->post('/applications/' . $applicationId . '/documents/upload-authorizations', $boot, [
            'requirement_id' => (string) $requirementId,
            'filename' => 'INFECTED-certificate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => '2048',
        ]);
        $authorization = json_decode((string) $authorizeResponse->getBody(), true);

        $fileContents = 'malicious payload';
        ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $authorization['upload_url'], 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withBody($this->bodyStream($fileContents))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        $this->post('/applications/' . $applicationId . '/documents/confirm', $boot, [
            'requirement_id' => (string) $requirementId,
            'object_key' => $authorization['object_key'],
            'checksum_sha256' => hash('sha256', $fileContents),
        ]);

        ApplicationFactory::container('testing')->get(DocumentScanWorker::class)->run('phpunit-worker');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT status, scan_status FROM document_submissions WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('failed_security_scan', $row['status']);
        self::assertSame('failed', $row['scan_status']);

        $response = $this->post('/applications/' . $applicationId . '/submit', $boot, []);
        self::assertStringContainsString('ok=0', $response->getHeaderLine('Location'));
    }

    private function bodyStream(string $contents): Stream
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($contents);
        $stream->rewind();

        return $stream;
    }

    private function createDraftApplication(array $boot, int $batchId): int
    {
        $response = $this->post('/applications', $boot, ['batch_id' => (string) $batchId]);
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/applications/\d+$#', $location);

        return (int) substr($location, strlen('/applications/'));
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
     * @return array{session: string, csrf: string, user_id: int}
     */
    private function bootApplicant(): array
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        return [
            'session' => $boot['session'],
            'csrf' => $boot['csrf'],
            'user_id' => $user['user_id'],
        ];
    }

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     * @param array<string, string> $body
     */
    private function post(string $path, array $boot, array $body): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody($body + ['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
