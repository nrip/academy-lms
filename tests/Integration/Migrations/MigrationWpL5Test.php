<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Migrations;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MigrationWpL5Test extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testAssessmentTablesTriggersAndPermissionExist(): void
    {
        $pdo = DatabaseTestCase::pdo();
        self::assertSame('assessments', $pdo->query("SHOW TABLES LIKE 'assessments'")->fetchColumn());
        self::assertSame(
            'assessment_question_links',
            $pdo->query("SHOW TABLES LIKE 'assessment_question_links'")->fetchColumn(),
        );

        $triggers = $pdo->query(
            "SHOW TRIGGERS WHERE `Table` IN ('assessments', 'assessment_question_links')",
        )->fetchAll();
        $names = array_map(static fn (array $row): string => (string) $row['Trigger'], $triggers);
        self::assertContains('trg_assessments_forbid_insert_when_locked', $names);
        self::assertContains('trg_assessment_question_links_forbid_insert_when_locked', $names);

        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('assessment.manage', $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN));
    }
}
