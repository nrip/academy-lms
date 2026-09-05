<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL8Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testCertificateTablesAndPermissionExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertSame('certificates', $pdo->query("SHOW TABLES LIKE 'certificates'")->fetchColumn());
        self::assertSame(
            'certificate_events',
            $pdo->query("SHOW TABLES LIKE 'certificate_events'")->fetchColumn(),
        );

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('certificate.view_own', $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT));
        self::assertContains('certificate.view_own', $repo->permissionKeysForRoleKey(RoleKeys::SUPER_ADMIN));
        self::assertNotContains('certificate.view_own', $repo->permissionKeysForRoleKey(RoleKeys::FINANCE_ADMIN));
    }
}
