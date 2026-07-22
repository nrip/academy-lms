<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Credentials;

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Credentials\DocumentDownloadService;
use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;

final class DocumentUploadResubmissionTest extends TestCase
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

    public function testAuthorizeConfirmCreatesOneCurrentRow(): void
    {
        $context = $this->seedReadyDraft();
        $uploads = $this->uploadService();

        $authorization = $uploads->authorizeUpload(
            $context['auth'],
            $context['application_id'],
            $context['requirement_id'],
            'certificate.pdf',
            'application/pdf',
            2048,
        );

        $contents = str_repeat('A', 2048);
        $uploads->receiveLocalUpload(
            $context['auth'],
            $context['application_id'],
            $authorization->authorizationId,
            $contents,
        );

        $submission = $uploads->confirmUpload(
            $context['auth'],
            $context['application_id'],
            $context['requirement_id'],
            $authorization->objectKey,
            hash('sha256', $contents),
        );

        self::assertSame(DocumentSubmissionStatus::UPLOADED, $submission->status);
        self::assertTrue($submission->isCurrent());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM document_submissions
             WHERE application_id = ? AND requirement_id = ? AND current_marker = 1',
        );
        $stmt->execute([$context['application_id'], $context['requirement_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testResubmissionSupersedesOldPreservesHistory(): void
    {
        $context = $this->seedReadyDraft();
        $uploads = $this->uploadService();

        $first = $this->uploadAndConfirm($uploads, $context, 'first.pdf', str_repeat('A', 2048));
        $second = $this->uploadAndConfirm($uploads, $context, 'second.pdf', str_repeat('B', 2048));

        self::assertNotSame($first->documentSubmissionId, $second->documentSubmissionId);
        self::assertTrue($second->isCurrent());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT document_submission_id, status, current_marker
             FROM document_submissions
             WHERE application_id = ? AND requirement_id = ?
             ORDER BY document_submission_id',
        );
        $stmt->execute([$context['application_id'], $context['requirement_id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $rows);
        self::assertSame('superseded', $rows[0]['status']);
        self::assertNull($rows[0]['current_marker']);
        self::assertSame('uploaded', $rows[1]['status']);
        self::assertSame(1, (int) $rows[1]['current_marker']);

        $currentCount = $pdo->prepare(
            'SELECT COUNT(*) FROM document_submissions
             WHERE application_id = ? AND requirement_id = ? AND current_marker = 1',
        );
        $currentCount->execute([$context['application_id'], $context['requirement_id']]);
        self::assertSame(1, (int) $currentCount->fetchColumn());
    }

    public function testScanWorkerCleanPath(): void
    {
        $context = $this->seedReadyDraft();
        $uploads = $this->uploadService();
        $this->uploadAndConfirm($uploads, $context, 'certificate.pdf', str_repeat('C', 2048));

        $processed = ApplicationFactory::container('testing')->get(DocumentScanWorker::class)->run('phpunit-resubmission');
        self::assertGreaterThanOrEqual(1, $processed);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT status, scan_status FROM document_submissions
             WHERE application_id = ? AND current_marker = 1',
        );
        $stmt->execute([$context['application_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('under_review', $row['status']);
        self::assertSame('clean', $row['scan_status']);
    }

    public function testInfectedPathFailsSecurityScan(): void
    {
        $context = $this->seedReadyDraft();
        $uploads = $this->uploadService();
        $this->uploadAndConfirm($uploads, $context, 'INFECTED-certificate.pdf', 'malicious payload');

        ApplicationFactory::container('testing')->get(DocumentScanWorker::class)->run('phpunit-infected');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT status, scan_status FROM document_submissions
             WHERE application_id = ? AND current_marker = 1',
        );
        $stmt->execute([$context['application_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('failed_security_scan', $row['status']);
        self::assertSame('failed', $row['scan_status']);
    }

    public function testFinanceUserCannotGetOwnSignedDownloadUrl(): void
    {
        $context = $this->seedReadyDraft();
        $uploads = $this->uploadService();
        $submission = $this->uploadAndConfirm($uploads, $context, 'certificate.pdf', str_repeat('D', 2048));
        ApplicationFactory::container('testing')->get(DocumentScanWorker::class)->run('phpunit-finance');

        $finance = DatabaseTestCase::financeFixture();
        $financeAuth = AuthContext::authenticated(
            userId: $finance['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $finance['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );

        $downloads = ApplicationFactory::container('testing')->get(DocumentDownloadService::class);

        $this->expectException(AuthorizationException::class);
        $downloads->getOwnSignedDownloadUrl(
            $financeAuth,
            $context['application_id'],
            $submission->documentSubmissionId,
        );
    }

    public function testFinanceUserCannotUseDocumentViewOwnPermission(): void
    {
        $finance = DatabaseTestCase::financeFixture();
        $financeAuth = AuthContext::authenticated(
            userId: $finance['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $finance['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );

        $authorization = ApplicationFactory::container('testing')->get(AuthorizationService::class);

        $this->expectException(AuthorizationException::class);
        $authorization->require($financeAuth, 'document.view_own');
    }

    /**
     * @return array{auth: AuthContext, application_id: int, requirement_id: int}
     */
    private function seedReadyDraft(): array
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
        $application = $container->get(DraftApplicationService::class)->createDraft($auth, $seeded['batch_id']);
        $container->get(ApplicationDeclarationService::class)->acceptOnDraft($auth, $application->applicationId);

        return [
            'auth' => $auth,
            'application_id' => $application->applicationId,
            'requirement_id' => $seeded['requirement_ids'][0],
        ];
    }

    private function uploadService(): DocumentUploadService
    {
        return ApplicationFactory::container('testing')->get(DocumentUploadService::class);
    }

    /**
     * @param array{auth: AuthContext, application_id: int, requirement_id: int} $context
     */
    private function uploadAndConfirm(
        DocumentUploadService $uploads,
        array $context,
        string $filename,
        string $contents,
    ): \Academy\Domain\Credentials\DocumentSubmission {
        $authorization = $uploads->authorizeUpload(
            $context['auth'],
            $context['application_id'],
            $context['requirement_id'],
            $filename,
            'application/pdf',
            strlen($contents),
        );
        $uploads->receiveLocalUpload(
            $context['auth'],
            $context['application_id'],
            $authorization->authorizationId,
            $contents,
        );

        return $uploads->confirmUpload(
            $context['auth'],
            $context['application_id'],
            $context['requirement_id'],
            $authorization->objectKey,
            hash('sha256', $contents),
        );
    }
}
