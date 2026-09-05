<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL1Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testCourseAdminRolePermissionsAndScopeTableExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        $role = $pdo->query("SELECT role_key, is_privileged FROM roles WHERE role_key = 'course_admin'")->fetch();
        self::assertNotFalse($role);
        self::assertSame('1', (string) $role['is_privileged']);

        $table = $pdo->query("SHOW TABLES LIKE 'course_admin_scope_assignments'")->fetchColumn();
        self::assertSame('course_admin_scope_assignments', $table);

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('course.create', $keys);
        self::assertContains('course.view_assigned', $keys);
        self::assertContains('course.version.edit', $keys);
        self::assertContains('mfa.totp.enrol', $keys);
    }
}
