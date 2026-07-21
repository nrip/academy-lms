<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class SegregationOfDutiesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
    }

    public function testFinanceHasNoDocumentPermissions(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::FINANCE_ADMIN);
        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            self::assertFalse(
                str_starts_with($key, 'document.'),
                'Finance must not have document permission: ' . $key,
            );
        }
    }

    public function testReviewerDoesNotReceiveFinanceRefundApprove(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        $keys = $repo->permissionKeysForRoleKey(RoleKeys::CREDENTIAL_REVIEWER);
        self::assertNotContains('finance.refund.approve', $keys);
    }
}
