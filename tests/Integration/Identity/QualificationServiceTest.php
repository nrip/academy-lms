<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\QualificationService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\QualificationValidator;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Identity\PdoLearnerProfileRepository;
use Academy\Infrastructure\Identity\PdoLearnerQualificationRepository;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class QualificationServiceTest extends TestCase
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

    public function testAddListUpdateDelete(): void
    {
        $auth = $this->applicant();
        $service = $this->buildService();

        $added = $service->add($auth, $this->payload('Degree', 'MBBS'));
        self::assertSame(1, $added->displayOrder);
        self::assertSame('MBBS', $added->qualificationName);

        $second = $service->add($auth, $this->payload('Diploma', 'PGDip'));
        self::assertSame(2, $second->displayOrder);

        $list = $service->list($auth);
        self::assertCount(2, $list['qualifications']);

        $updated = $service->update($auth, $added->learnerQualificationId, $added->rowVersion, $this->payload('Degree', 'MBBS (Hons)'));
        self::assertSame('MBBS (Hons)', $updated->qualificationName);
        self::assertSame(2, $updated->rowVersion);

        $service->delete($auth, $second->learnerQualificationId, $second->rowVersion);
        $list = $service->list($auth);
        self::assertCount(1, $list['qualifications']);
    }

    public function testUpdateWithStaleVersionConflicts(): void
    {
        $auth = $this->applicant();
        $service = $this->buildService();
        $added = $service->add($auth, $this->payload('Degree', 'MBBS'));
        $service->update($auth, $added->learnerQualificationId, $added->rowVersion, $this->payload('Degree', 'MBBS v2'));

        $this->expectException(ConflictException::class);
        $service->update($auth, $added->learnerQualificationId, $added->rowVersion, $this->payload('Degree', 'MBBS v3'));
    }

    public function testCannotUpdateAnotherUsersQualification(): void
    {
        $owner = $this->applicant();
        $intruder = $this->applicant();
        $service = $this->buildService();

        $added = $service->add($owner, $this->payload('Degree', 'MBBS'));

        $this->expectException(AuthorizationException::class);
        $service->update($intruder, $added->learnerQualificationId, $added->rowVersion, $this->payload('Degree', 'Hacked'));
    }

    public function testCannotDeleteAnotherUsersQualification(): void
    {
        $owner = $this->applicant();
        $intruder = $this->applicant();
        $service = $this->buildService();

        $added = $service->add($owner, $this->payload('Degree', 'MBBS'));

        try {
            $service->delete($intruder, $added->learnerQualificationId, $added->rowVersion);
            self::fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        self::assertCount(1, $service->list($owner)['qualifications']);
    }

    public function testMaxTwentyQualificationsEnforced(): void
    {
        $auth = $this->applicant();
        $service = $this->buildService();

        for ($i = 1; $i <= QualificationService::MAX_QUALIFICATIONS; $i++) {
            $service->add($auth, $this->payload('Degree', 'Q' . $i));
        }

        $this->expectException(DomainRuleException::class);
        $service->add($auth, $this->payload('Degree', 'Overflow'));
    }

    private function buildService(): QualificationService
    {
        return new QualificationService(
            new TransactionManager($this->factory),
            new PdoLearnerProfileRepository($this->factory),
            new PdoLearnerQualificationRepository($this->factory),
            new AuthorizationService(new PdoPermissionRepository($this->factory)),
            new AuditService(new PdoAuditWriter($this->factory), new AuditRedactor()),
            new QualificationValidator(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $type, string $name): array
    {
        return [
            'qualification_type' => $type,
            'qualification_name' => $name,
            'institution_name' => 'AIIMS',
            'completion_year' => '2010',
        ];
    }

    private function applicant(): AuthContext
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);

        return AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );
    }
}
