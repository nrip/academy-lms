<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class RbacMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testSeededMatrixMatchesCatalogue(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());

        $applicant = $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT);
        self::assertContains('application.create', $applicant);
        self::assertContains('identity.session.view_own', $applicant);
        self::assertNotContains('rbac.role.assign', $applicant);
        self::assertNotContains('document.metadata.view', $applicant);

        $finance = $repo->permissionKeysForRoleKey(RoleKeys::FINANCE_ADMIN);
        self::assertContains('finance.refund.approve', $finance);
        self::assertNotContains('document.metadata.view', $finance);

        $reviewer = $repo->permissionKeysForRoleKey(RoleKeys::CREDENTIAL_REVIEWER);
        self::assertContains('document.metadata.view', $reviewer);
        self::assertContains('reviewer.document.review', $reviewer);
        self::assertNotContains('finance.refund.approve', $reviewer);

        $super = $repo->permissionKeysForRoleKey(RoleKeys::SUPER_ADMIN);
        self::assertContains('rbac.role.assign', $super);
        self::assertContains('document.signed_url.generate', $super);
        self::assertContains('finance.refund.approve', $super);
        self::assertContains('audit.view', $super);
        self::assertContains('course.create', $super);
        self::assertContains('course.admin.scope.assign', $super);

        $courseAdmin = $repo->permissionKeysForRoleKey(RoleKeys::COURSE_ADMIN);
        self::assertContains('course.create', $courseAdmin);
        self::assertContains('course.view_assigned', $courseAdmin);
        self::assertContains('course.version.edit', $courseAdmin);
        self::assertContains('module.manage', $courseAdmin);
        self::assertContains('content.manage', $courseAdmin);
        self::assertContains('question_bank.manage', $courseAdmin);
        self::assertNotContains('document.metadata.view', $courseAdmin);
        self::assertNotContains('finance.refund.approve', $courseAdmin);
        self::assertNotContains('course.admin.scope.assign', $courseAdmin);

        self::assertContains('module.manage', $super);
        self::assertContains('content.manage', $super);
        self::assertContains('question_bank.manage', $super);
    }
}
