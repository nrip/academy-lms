<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Admissions;

use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Courses\BatchAvailabilityEvaluator;
use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Admissions\PdoApplicationRepository;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Courses\PdoBatchRepository;
use Academy\Infrastructure\Courses\PdoCourseRepository;
use Academy\Infrastructure\Courses\PdoCourseVersionRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class DraftApplicationServiceTest extends TestCase
{
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
        $this->factory = DatabaseTestCase::connectionFactory();
    }

    public function testCreateDraftSucceedsForOpenBatch(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $application = $service->createDraft($auth, $seeded['batch_id']);

        self::assertSame(ApplicationStatus::DRAFT, $application->status);
        self::assertSame($seeded['batch_id'], $application->batchId);
        self::assertSame($seeded['version_id'], $application->courseVersionId);
        self::assertNull($application->submittedAt);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'application.draft_created'");
        $stmt->execute();
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testCreateDraftIsIdempotentForSameUserAndBatch(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $first = $service->createDraft($auth, $seeded['batch_id']);
        $second = $service->createDraft($auth, $seeded['batch_id']);

        self::assertSame($first->applicationId, $second->applicationId);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE user_id = ? AND batch_id = ?');
        $stmt->execute([$auth->userId, $seeded['batch_id']]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testCreateDraftConflictsWhenExistingApplicationIsNotDraft(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $application = $service->createDraft($auth, $seeded['batch_id']);
        $this->forceStatus($application->applicationId, ApplicationStatus::SUBMITTED);

        $this->expectException(ConflictException::class);
        $service->createDraft($auth, $seeded['batch_id']);
    }

    public function testCreateDraftThrowsDomainRuleExceptionForPlannedBatch(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id'], ['status' => BatchStatus::PLANNED]);
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $this->expectException(DomainRuleException::class);
        $service->createDraft($auth, $batchId);
    }

    public function testCreateDraftThrowsDomainRuleExceptionForFullBatch(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id'], ['status' => BatchStatus::FULL]);
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $this->expectException(DomainRuleException::class);
        $service->createDraft($auth, $batchId);
    }

    public function testCreateDraftThrowsNotFoundForUnknownBatch(): void
    {
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $this->expectException(NotFoundException::class);
        $service->createDraft($auth, 999999);
    }

    public function testCreateDraftHiddenForUnpublishedCourseVersion(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse([
            'version_status' => \Academy\Domain\Courses\CourseVersionStatus::DRAFT,
            'locked' => false,
            'set_current_published_version' => false,
        ]);
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id']);
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $this->expectException(DomainRuleException::class);
        $service->createDraft($auth, $batchId);
    }

    public function testFinanceUserCannotCreateDraft(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $finance = DatabaseTestCase::financeFixture();
        $auth = AuthContext::authenticated(
            userId: $finance['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $finance['auth_version'],
            hasPrivilegedRole: true,
            accountStatus: AccountStatus::ACTIVE,
        );
        $service = $this->buildService();

        $this->expectException(AuthorizationException::class);
        $service->createDraft($auth, $seeded['batch_id']);
    }

    public function testGetOwnReturnsApplicationForOwner(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $auth = $this->applicantAuth();
        $service = $this->buildService();

        $application = $service->createDraft($auth, $seeded['batch_id']);
        $found = $service->getOwn($auth, $application->applicationId);

        self::assertSame($application->applicationId, $found->applicationId);
    }

    public function testGetOwnThrowsNotFoundForOtherUsersApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $owner = $this->applicantAuth();
        $service = $this->buildService();
        $application = $service->createDraft($owner, $seeded['batch_id']);

        $intruder = $this->applicantAuth();

        $this->expectException(NotFoundException::class);
        $service->getOwn($intruder, $application->applicationId);
    }

    private function buildService(): DraftApplicationService
    {
        return new DraftApplicationService(
            new TransactionManager($this->factory),
            new AuthorizationService(new PdoPermissionRepository($this->factory)),
            new PdoBatchRepository($this->factory),
            new PdoCourseVersionRepository($this->factory),
            new PdoCourseRepository($this->factory),
            new PdoApplicationRepository($this->factory),
            new BatchAvailabilityEvaluator(),
            new AuditService(new PdoAuditWriter($this->factory), new AuditRedactor()),
        );
    }

    private function applicantAuth(): AuthContext
    {
        $user = DatabaseTestCase::applicantFixture();

        return AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );
    }

    private function forceStatus(int $applicationId, string $status): void
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('UPDATE applications SET status = :status WHERE application_id = :id');
        $stmt->execute(['status' => $status, 'id' => $applicationId]);
    }
}
