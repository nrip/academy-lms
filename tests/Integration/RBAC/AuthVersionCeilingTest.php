<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\RBAC;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\RoleAssignmentService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\AuthVersionCeilingException;
use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\RBAC\PdoRoleRepository;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AuthVersionCeilingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testAuthVersionCeilingFailsClosed(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'ceiling@example.test',
            '+916188888888',
            [],
            authVersion: AuthVersion::CEILING,
        );

        $factory = DatabaseTestCase::connectionFactory();
        $service = new RoleAssignmentService(
            new TransactionManager($factory),
            new PdoRoleRepository($factory),
            new AuditService(new PdoAuditWriter($factory), new AuditRedactor()),
            new SessionService(
                new PdoSessionRepository($factory),
                new CsrfTokenManager(),
                new NullLogger(),
                ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
                ['idle_seconds' => 900, 'absolute_seconds' => 28800],
                300,
            ),
        );

        $this->expectException(AuthVersionCeilingException::class);
        $service->assign($user['user_id'], RoleKeys::APPLICANT, null);
    }
}
