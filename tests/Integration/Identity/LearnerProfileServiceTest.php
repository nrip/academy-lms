<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\LearnerProfileService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\AuditRecord;
use Academy\Domain\Audit\AuditWriter;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\PersonalProfileValidator;
use Academy\Domain\Identity\ProfessionalProfileValidator;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Identity\PdoLearnerProfileRepository;
use Academy\Infrastructure\Identity\PdoLearnerQualificationRepository;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LearnerProfileServiceTest extends TestCase
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

    public function testUpdatePersonalPersistsAndIncrementsRowVersion(): void
    {
        [$auth, $profileId] = $this->applicantWithProfile();
        $service = $this->buildService();

        $updated = $service->updatePersonal($auth, 1, [
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'preferred_display_name' => 'Dr Rao',
        ]);

        self::assertSame('Asha', $updated->firstName);
        self::assertSame(2, $updated->rowVersion);

        $reloaded = (new PdoLearnerProfileRepository($this->factory))->findById($profileId);
        self::assertNotNull($reloaded);
        self::assertSame('Rao', $reloaded->lastName);
    }

    public function testStaleRowVersionThrowsConflict(): void
    {
        [$auth] = $this->applicantWithProfile();
        $service = $this->buildService();

        $service->updatePersonal($auth, 1, ['first_name' => 'First']);

        $this->expectException(ConflictException::class);
        $service->updatePersonal($auth, 1, ['first_name' => 'Second']);
    }

    public function testUpdateProfessionalPersists(): void
    {
        [$auth] = $this->applicantWithProfile();
        $service = $this->buildService();

        $updated = $service->updateProfessional($auth, 1, [
            'profession' => 'Physician',
            'years_of_experience' => '15',
        ]);

        self::assertSame('Physician', $updated->profession);
        self::assertSame(15, $updated->yearsOfExperience);
        self::assertSame(2, $updated->rowVersion);
    }

    public function testAuditRecordedWithoutSensitiveValues(): void
    {
        [$auth, $profileId] = $this->applicantWithProfile();
        $service = $this->buildService();

        $service->updatePersonal($auth, 1, [
            'first_name' => 'Asha',
            'address_line_1' => '221B Baker Street',
            'alternate_mobile' => '9876543210',
        ]);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            "SELECT action, affected_entity_id, new_value FROM audit_log WHERE action = 'profile.personal_updated'",
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);
        self::assertSame((string) $profileId, $row['affected_entity_id']);

        $newValue = (string) $row['new_value'];
        self::assertStringNotContainsString('221B Baker Street', $newValue);
        self::assertStringNotContainsString('9876543210', $newValue);
        self::assertStringContainsString('changed_field_keys', $newValue);
    }

    public function testAuditFailureRollsBackUpdate(): void
    {
        [$auth, $profileId] = $this->applicantWithProfile();
        $failingWriter = new class () implements AuditWriter {
            public function append(AuditRecord $record): void
            {
                throw new RuntimeException('audit boom');
            }
        };
        $service = $this->buildService($failingWriter);

        try {
            $service->updatePersonal($auth, 1, ['first_name' => 'Asha']);
            self::fail('Expected the audit failure to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('audit boom', $exception->getMessage());
        }

        $reloaded = (new PdoLearnerProfileRepository($this->factory))->findById($profileId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->firstName, 'Update must roll back when the audit write fails.');
        self::assertSame(1, $reloaded->rowVersion);
    }

    public function testOverviewComputesCompleteness(): void
    {
        [$auth, $profileId] = $this->applicantWithProfile();
        $service = $this->buildService();

        $service->updatePersonal($auth, 1, [
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'preferred_display_name' => 'Dr Rao',
            'certificate_name' => 'Dr Asha Rao',
            'certificate_name_confirmed' => '1',
            'date_of_birth' => '1990-01-01',
            'address_line_1' => '1 Road',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'postal_code' => '560001',
            'country' => 'India',
        ]);

        $overview = $service->overview($auth);

        self::assertGreaterThan(0, $overview['completeness']->percentage);
        self::assertContains('core_personal', $overview['completeness']->completedSections);
        self::assertContains('contact_address', $overview['completeness']->completedSections);
        self::assertSame(0, $overview['qualifications_count']);
        self::assertSame($profileId, $overview['profile']->learnerProfileId);
    }

    private function buildService(?AuditWriter $writer = null): LearnerProfileService
    {
        return new LearnerProfileService(
            new TransactionManager($this->factory),
            new PdoLearnerProfileRepository($this->factory),
            new PdoLearnerQualificationRepository($this->factory),
            new AuthorizationService(new PdoPermissionRepository($this->factory)),
            new AuditService($writer ?? new PdoAuditWriter($this->factory), new AuditRedactor()),
            new PersonalProfileValidator(),
            new ProfessionalProfileValidator(),
            new ProfileCompletenessCalculator(),
        );
    }

    /**
     * @return array{0: AuthContext, 1: int}
     */
    private function applicantWithProfile(): array
    {
        $user = DatabaseTestCase::applicantFixture();
        $profileId = DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
        $auth = AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        return [$auth, $profileId];
    }
}
