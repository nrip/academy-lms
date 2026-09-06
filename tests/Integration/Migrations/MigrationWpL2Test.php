<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL2Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testClonePublishBatchSchemaAndPermissionsExist(): void
    {
        $pdo = DatabaseTestCase::pdo();

        $columns = $pdo->query('SHOW COLUMNS FROM course_versions LIKE \'cloned_from_version_id\'')->fetchAll();
        self::assertCount(1, $columns);

        self::assertSame(
            'course_version_status_history',
            $pdo->query("SHOW TABLES LIKE 'course_version_status_history'")->fetchColumn(),
        );

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        foreach ([RoleKeys::COURSE_ADMIN, RoleKeys::SUPER_ADMIN] as $role) {
            $keys = $repo->permissionKeysForRoleKey($role);
            self::assertContains('course.version.clone', $keys);
            self::assertContains('course.version.publish', $keys);
            self::assertContains('batch.create', $keys);
        }
    }
}
