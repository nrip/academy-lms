<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL7Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testAttemptTablesAndPermissionExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertSame(
            'assessment_attempts',
            $pdo->query("SHOW TABLES LIKE 'assessment_attempts'")->fetchColumn(),
        );
        self::assertSame(
            'assessment_attempt_questions',
            $pdo->query("SHOW TABLES LIKE 'assessment_attempt_questions'")->fetchColumn(),
        );
        self::assertSame(
            'assessment_responses',
            $pdo->query("SHOW TABLES LIKE 'assessment_responses'")->fetchColumn(),
        );
        self::assertSame(
            'assessment_attempt_status_history',
            $pdo->query("SHOW TABLES LIKE 'assessment_attempt_status_history'")->fetchColumn(),
        );

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('assessment.attempt.own', $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT));
        self::assertContains('assessment.attempt.own', $repo->permissionKeysForRoleKey(RoleKeys::SUPER_ADMIN));
        self::assertNotContains('assessment.attempt.own', $repo->permissionKeysForRoleKey(RoleKeys::FINANCE_ADMIN));
    }
}
