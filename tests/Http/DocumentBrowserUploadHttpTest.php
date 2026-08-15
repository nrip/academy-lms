<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Credentials\DocumentFileValidator;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Browser multipart document upload on the documents workspace.
 */
final class DocumentBrowserUploadHttpTest extends TestCase
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

    public function testDocumentsPageContainsRealMultipartUploadForm(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();

        $response = $this->get('/applications/' . $applicationId . '/documents', $boot);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('name="document"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString(
            'action="/applications/' . $applicationId . '/documents/upload"',
            $html,
        );
        self::assertStringContainsString('name="requirement_id"', $html);
        self::assertStringContainsString('value="' . $requirementId . '"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('type="submit"', $html);
        self::assertMatchesRegularExpression('/>\s*Upload\s*<\/button>/', $html);
        // Dead JS-only button must not be the only control.
        self::assertStringNotContainsString('acad-document-upload-btn', $html);
    }

    public function testValidMultipartUploadSucceedsAndAppearsOnPage(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();
        $contents = str_repeat('A', 2048);

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'certificate.pdf',
            'application/pdf',
            $contents,
        );
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/applications/' . $applicationId . '/documents', $location);
        self::assertStringContainsString('uploaded=1', $location);

        $page = $this->get('/applications/' . $applicationId . '/documents', $boot);
        $html = (string) $page->getBody();
        self::assertStringContainsString('certificate.pdf', $html);
        self::assertStringContainsString('Uploaded', $html);
        self::assertStringContainsString('Scan pending', $html);
    }

    public function testMissingFileShowsValidationError(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            null,
            null,
            null,
            UPLOAD_ERR_NO_FILE,
        );
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('error=', $location);

        $page = $this->get($location, $boot);
        $html = (string) $page->getBody();
        self::assertStringContainsString('Please choose a file', $html);
    }

    public function testInvalidTypeShowsValidationError(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'malware.exe',
            'application/octet-stream',
            str_repeat('X', 512),
        );
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('error=', $location);

        $page = $this->get($location, $boot);
        $html = (string) $page->getBody();
        self::assertMatchesRegularExpression('/not allowed|not accepted|File type/i', $html);
    }

    public function testOnePointOneMbPdfSucceeds(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();
        $contents = str_repeat('B', (int) (1.1 * 1024 * 1024));

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'large-certificate.pdf',
            'application/pdf',
            $contents,
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('uploaded=1', $response->getHeaderLine('Location'));

        $page = $this->get('/applications/' . $applicationId . '/documents', $boot);
        $html = (string) $page->getBody();
        self::assertStringContainsString('large-certificate.pdf', $html);
        self::assertStringContainsString('Max size 10 MB', $html);
    }

    public function testTenMbBoundarySucceeds(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();
        $contents = str_repeat('C', DocumentFileValidator::PLATFORM_MAX_BYTES);

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'boundary.pdf',
            'application/pdf',
            $contents,
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('uploaded=1', $response->getHeaderLine('Location'));
    }

    public function testOverTenMbRejectedByApplicationValidation(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();
        $contents = str_repeat('D', DocumentFileValidator::PLATFORM_MAX_BYTES + 1);

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'too-big.pdf',
            'application/pdf',
            $contents,
        );
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('error=', $location);

        $page = $this->get($location, $boot);
        $html = (string) $page->getBody();
        self::assertStringContainsString('larger than the 10 MB limit', $html);
    }

    public function testIniSizeErrorMapsToServerConfigurationMessage(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'any.pdf',
            'application/pdf',
            'x',
            UPLOAD_ERR_INI_SIZE,
        );
        self::assertSame(303, $response->getStatusCode());
        $page = $this->get($response->getHeaderLine('Location'), $boot);
        $html = (string) $page->getBody();
        self::assertStringContainsString('server is currently configured to accept files only up to', $html);
        self::assertStringNotContainsString('File exceeds the maximum allowed size', $html);
    }

    public function testPartialUploadMapsCorrectly(): void
    {
        [$boot, $applicationId, $requirementId] = $this->prepareDraftApplication();

        $response = $this->postMultipart(
            '/applications/' . $applicationId . '/documents/upload',
            $boot,
            [
                'requirement_id' => (string) $requirementId,
                '_csrf' => $boot['csrf'],
            ],
            'partial.pdf',
            'application/pdf',
            'x',
            UPLOAD_ERR_PARTIAL,
        );
        self::assertSame(303, $response->getStatusCode());
        $page = $this->get($response->getHeaderLine('Location'), $boot);
        self::assertStringContainsString('interrupted', (string) $page->getBody());
    }

    /**
     * @return array{0: array{session: string, csrf: string, user_id: int}, 1: int, 2: int}
     */
    private function prepareDraftApplication(): array
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [[
                'document_name' => 'Registration certificate',
                'mandatory' => true,
                'max_size_bytes' => DocumentFileValidator::PLATFORM_MAX_BYTES,
            ]],
        );
        $requirementId = $seeded['requirement_ids'][0];
        $boot = $this->bootApplicant();
        $this->seedCompleteProfile($boot['user_id']);
        $applicationId = $this->createDraftApplication($boot, $seeded['batch_id']);
        $this->post('/applications/' . $applicationId, $boot, []);

        return [$boot, $applicationId, $requirementId];
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
     * @param array{session: string, csrf: string, user_id: int} $boot
     */
    private function createDraftApplication(array $boot, int $batchId): int
    {
        $response = $this->post('/applications', $boot, ['batch_id' => (string) $batchId]);
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#/applications/(\d+)#', $location);
        preg_match('#/applications/(\d+)#', $location, $m);

        return (int) $m[1];
    }

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     */
    private function get(string $path, array $boot): ResponseInterface
    {
        if (!str_starts_with($path, 'http://')) {
            $path = 'http://localhost' . $path;
        }
        $parts = parse_url($path);
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

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     * @param array<string, string> $fields
     */
    private function postMultipart(
        string $path,
        array $boot,
        array $fields,
        ?string $filename,
        ?string $mime,
        ?string $contents,
        int $uploadError = UPLOAD_ERR_OK,
    ): ResponseInterface {
        $uploaded = null;
        if ($filename !== null && $contents !== null && $mime !== null) {
            $stream = new Stream('php://temp', 'wb+');
            $stream->write($contents);
            $stream->rewind();
            $uploaded = new UploadedFile(
                $stream,
                strlen($contents),
                $uploadError,
                $filename,
                $mime,
            );
        } else {
            $stream = new Stream('php://temp', 'wb+');
            $uploaded = new UploadedFile($stream, 0, $uploadError, null, null);
        }

        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
                ->withHeader('Content-Type', 'multipart/form-data; boundary=----test')
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody($fields)
                ->withUploadedFiles(['document' => $uploaded])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
