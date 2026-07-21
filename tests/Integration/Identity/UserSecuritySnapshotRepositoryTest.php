<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Infrastructure\Identity\PdoUserSecuritySnapshotRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class UserSecuritySnapshotRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testSnapshotDetectsPrivilegedRoleAndLockFields(): void
    {
        $lockedUntil = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 minutes');
        $user = DatabaseTestCase::createSyntheticUser(
            'snap@example.test',
            '+916155555555',
            ['super_admin'],
            AccountStatus::ACTIVE,
            $lockedUntil,
            3,
        );
        $repo = new PdoUserSecuritySnapshotRepository(DatabaseTestCase::connectionFactory());
        $snapshot = $repo->findById($user['user_id']);
        self::assertNotNull($snapshot);
        self::assertTrue($snapshot->hasPrivilegedRole);
        self::assertSame(3, $snapshot->authVersion);
        self::assertTrue($snapshot->isTemporarilyLocked(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
    }

    public function testApplicantIsNotPrivileged(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $repo = new PdoUserSecuritySnapshotRepository(DatabaseTestCase::connectionFactory());
        $snapshot = $repo->findById($user['user_id']);
        self::assertNotNull($snapshot);
        self::assertFalse($snapshot->hasPrivilegedRole);
        self::assertSame(AuthStage::FULLY_AUTHENTICATED, AuthStage::resolveEffective(null, $snapshot->hasPrivilegedRole));
    }
}
