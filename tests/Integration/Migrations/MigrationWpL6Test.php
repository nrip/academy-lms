<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL6Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testContentProgressTableAndPermissionExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertSame(
            'content_progress',
            $pdo->query("SHOW TABLES LIKE 'content_progress'")->fetchColumn(),
        );

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('learning.content.access', $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT));
        self::assertContains('learning.content.access', $repo->permissionKeysForRoleKey(RoleKeys::SUPER_ADMIN));
    }
}
