<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\RBAC;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\RoleAssignmentService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\RBAC\PdoRoleRepository;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RoleAssignmentServiceTest extends TestCase
{
    private RoleAssignmentService $service;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $factory = DatabaseTestCase::connectionFactory();
        $sessions = new SessionService(
            new PdoSessionRepository($factory),
            new CsrfTokenManager(),
            new NullLogger(),
            ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
            ['idle_seconds' => 900, 'absolute_seconds' => 28800],
            300,
        );
        $this->service = new RoleAssignmentService(
            new TransactionManager($factory),
            new PdoRoleRepository($factory),
            new AuditService(new PdoAuditWriter($factory), new AuditRedactor()),
            $sessions,
        );
    }

    public function testAssignRevokeReassignPreservesHistoryAndIncrementsAuthVersion(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'rbac.history@example.test',
            '+916111111111',
            [],
        );
        $initial = DatabaseTestCase::authVersion($user['user_id']);

        $this->service->assign($user['user_id'], RoleKeys::APPLICANT, null, 'test assign');
        self::assertSame($initial + 1, DatabaseTestCase::authVersion($user['user_id']));
        self::assertSame(1, $this->currentMarkerCount($user['user_id'], RoleKeys::APPLICANT));

        $this->service->revoke($user['user_id'], RoleKeys::APPLICANT, null, 'test revoke');
        self::assertSame($initial + 2, DatabaseTestCase::authVersion($user['user_id']));
        self::assertSame(0, $this->currentMarkerCount($user['user_id'], RoleKeys::APPLICANT));
        self::assertSame(1, $this->historicalCount($user['user_id'], RoleKeys::APPLICANT));

        $this->service->reassign($user['user_id'], RoleKeys::APPLICANT, null, 'test reassign');
        self::assertSame($initial + 3, DatabaseTestCase::authVersion($user['user_id']));
        self::assertSame(1, $this->currentMarkerCount($user['user_id'], RoleKeys::APPLICANT));
        self::assertSame(2, $this->totalRows($user['user_id'], RoleKeys::APPLICANT));
    }

    public function testDuplicateAssignConflicts(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'rbac.dup@example.test',
            '+916122222222',
            [RoleKeys::APPLICANT],
        );
        $this->expectException(ConflictException::class);
        $this->service->assign($user['user_id'], RoleKeys::APPLICANT, null);
    }

    public function testMysqlRejectsInvalidCurrentMarker(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'rbac.marker@example.test',
            '+916133333333',
            [],
        );
        $roleId = DatabaseTestCase::roleId(RoleKeys::APPLICANT);
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $this->expectException(\PDOException::class);
        $stmt = $pdo->prepare(
            'INSERT INTO user_roles (
                user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
            ) VALUES (?, ?, NULL, ?, 2, ?, ?)',
        );
        $stmt->execute([$user['user_id'], $roleId, $now, $now, $now]);
    }

    private function currentMarkerCount(int $userId, string $roleKey): int
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_key = ? AND ur.current_marker = 1',
        );
        $stmt->execute([$userId, $roleKey]);

        return (int) $stmt->fetchColumn();
    }

    private function historicalCount(int $userId, string $roleKey): int
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_key = ? AND ur.current_marker IS NULL',
        );
        $stmt->execute([$userId, $roleKey]);

        return (int) $stmt->fetchColumn();
    }

    private function totalRows(int $userId, string $roleKey): int
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_key = ?',
        );
        $stmt->execute([$userId, $roleKey]);

        return (int) $stmt->fetchColumn();
    }
}
