<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL4Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testQuestionBankTablesAndPermissionExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertSame('question_banks', $pdo->query("SHOW TABLES LIKE 'question_banks'")->fetchColumn());
        self::assertSame('questions', $pdo->query("SHOW TABLES LIKE 'questions'")->fetchColumn());
        self::assertSame('question_options', $pdo->query("SHOW TABLES LIKE 'question_options'")->fetchColumn());

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('question_bank.manage', $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN));
        self::assertContains('question_bank.manage', $repo->permissionKeysForRoleKey(RoleKeys::SUPER_ADMIN));
    }
}
