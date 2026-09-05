<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL3Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testModulesContentTablesTriggersAndPermissionsExist(): void
    {
        $pdo = DatabaseTestCase::pdo();

        self::assertSame('modules', $pdo->query("SHOW TABLES LIKE 'modules'")->fetchColumn());
        self::assertSame('content_items', $pdo->query("SHOW TABLES LIKE 'content_items'")->fetchColumn());

        $triggers = $pdo->query("SHOW TRIGGERS WHERE `Table` IN ('modules', 'content_items')")->fetchAll();
        $names = array_map(static fn (array $row): string => (string) $row['Trigger'], $triggers);
        self::assertContains('trg_modules_forbid_insert_when_locked', $names);
        self::assertContains('trg_modules_forbid_update_when_locked', $names);
        self::assertContains('trg_modules_forbid_delete_when_locked', $names);
        self::assertContains('trg_content_items_forbid_insert_when_locked', $names);
        self::assertContains('trg_content_items_forbid_update_when_locked', $names);
        self::assertContains('trg_content_items_forbid_delete_when_locked', $names);

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('module.manage', $keys);
        self::assertContains('content.manage', $keys);
    }
}
