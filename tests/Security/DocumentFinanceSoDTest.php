<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Segregation-of-duties: Finance must never access learner document metadata
 * or signed download routes.
 */
final class DocumentFinanceSoDTest extends TestCase
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

    public function testFinanceAdminCannotViewDocumentMetadataPage(): void
    {
        $fixture = $this->seedApplicantDocument();

        $finance = DatabaseTestCase::financeFixture();
        $boot = $this->boot($finance['user_id'], $finance['auth_version']);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/applications/' . $fixture['application_id'] . '/documents', 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testFinanceAdminCannotAccessSignedDocumentDownloadRoute(): void
    {
        $fixture = $this->seedApplicantDocument();

        $finance = DatabaseTestCase::financeFixture();
        $boot = $this->boot($finance['user_id'], $finance['auth_version']);

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/applications/' . $fixture['application_id']
                    . '/documents/' . $fixture['submission_id'] . '/download',
                'GET',
            ))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return array{application_id: int, submission_id: int}
     */
    private function seedApplicantDocument(): array
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogueWithRequirements(
            requirementOverridesList: [['document_name' => 'Registration certificate', 'mandatory' => true]],
        );
        $user = DatabaseTestCase::applicantFixture();
        $auth = \Academy\Domain\Security\AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: \Academy\Domain\Identity\AccountStatus::ACTIVE,
        );

        $container = ApplicationFactory::container('testing');
        $application = $container->get(DraftApplicationService::class)->createDraft($auth, $seeded['batch_id']);
        $container->get(ApplicationDeclarationService::class)->acceptOnDraft($auth, $application->applicationId);

        $uploads = $container->get(DocumentUploadService::class);
        $contents = str_repeat('E', 2048);
        $authorization = $uploads->authorizeUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            'certificate.pdf',
            'application/pdf',
            strlen($contents),
        );
        $uploads->receiveLocalUpload($auth, $application->applicationId, $authorization->authorizationId, $contents);
        $submission = $uploads->confirmUpload(
            $auth,
            $application->applicationId,
            $seeded['requirement_ids'][0],
            $authorization->objectKey,
            hash('sha256', $contents),
        );
        $container->get(DocumentScanWorker::class)->run('phpunit-finance-sod');

        return [
            'application_id' => $application->applicationId,
            'submission_id' => $submission->documentSubmissionId,
        ];
    }

    /**
     * @return array{session: string, csrf: string}
     */
    private function boot(int $userId, int $authVersion): array
    {
        $boot = DatabaseTestCase::bindSessionForUser($userId, $authVersion, AuthStage::FULLY_AUTHENTICATED);

        return ['session' => $boot['session'], 'csrf' => $boot['csrf']];
    }
}
