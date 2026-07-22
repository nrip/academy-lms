<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\InitialApplicantRoleBinder;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Http\Controllers\RegistrationController;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use League\Route\Router;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural coverage for InitialApplicantRoleBinder: real MySQL role assignment,
 * auth_version/session invariants, audit trail, and non-exposure as an HTTP route.
 */
final class InitialApplicantRoleBinderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();
    }

    public function testBindAssignsOnlyApplicantRole(): void
    {
        $pdo = DatabaseTestCase::pdo();
        $userId = $this->insertRawPendingUser($pdo, 'binder.only.' . bin2hex(random_bytes(4)) . '@example.test');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $audit = new AuditService(new PdoAuditWriter(DatabaseTestCase::connectionFactory()), new AuditRedactor());
        $binder = new InitialApplicantRoleBinder();

        $binder->bind($pdo, $userId, $now, $audit);

        $stmt = $pdo->prepare(
            'SELECT r.role_key, ur.current_marker
             FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = :user_id',
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        self::assertCount(1, $rows);
        self::assertSame(RoleKeys::APPLICANT, $rows[0]['role_key']);
        self::assertSame(1, (int) $rows[0]['current_marker']);
    }

    public function testAuthVersionIsNotIncremented(): void
    {
        $pdo = DatabaseTestCase::pdo();
        $userId = $this->insertRawPendingUser($pdo, 'binder.authver.' . bin2hex(random_bytes(4)) . '@example.test');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $audit = new AuditService(new PdoAuditWriter(DatabaseTestCase::connectionFactory()), new AuditRedactor());
        $binder = new InitialApplicantRoleBinder();

        $before = DatabaseTestCase::authVersion($userId);
        self::assertSame(1, $before);

        $binder->bind($pdo, $userId, $now, $audit);

        $after = DatabaseTestCase::authVersion($userId);
        self::assertSame($before, $after);
        self::assertSame(1, $after);
    }

    public function testNoSessionsAreRevokedBecauseNoneExistYet(): void
    {
        $pdo = DatabaseTestCase::pdo();
        $userId = $this->insertRawPendingUser($pdo, 'binder.sessions.' . bin2hex(random_bytes(4)) . '@example.test');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $audit = new AuditService(new PdoAuditWriter(DatabaseTestCase::connectionFactory()), new AuditRedactor());
        $binder = new InitialApplicantRoleBinder();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ?');
        $countStmt->execute([$userId]);
        self::assertSame(0, (int) $countStmt->fetchColumn());

        $binder->bind($pdo, $userId, $now, $audit);

        $countStmt->execute([$userId]);
        self::assertSame(0, (int) $countStmt->fetchColumn(), 'Binder must not create or revoke any sessions.');
    }

    public function testAuditsRbacRoleAssignWithRegistrationReason(): void
    {
        $pdo = DatabaseTestCase::pdo();
        $userId = $this->insertRawPendingUser($pdo, 'binder.audit.' . bin2hex(random_bytes(4)) . '@example.test');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $audit = new AuditService(new PdoAuditWriter(DatabaseTestCase::connectionFactory()), new AuditRedactor());
        $binder = new InitialApplicantRoleBinder();

        $binder->bind($pdo, $userId, $now, $audit);

        $stmt = $pdo->prepare(
            "SELECT action, reason, new_value, actor_type, actor_user_id
             FROM audit_log
             WHERE action = 'rbac.role.assign' AND affected_entity_type = 'user_role'
             ORDER BY audit_id DESC LIMIT 1",
        );
        $stmt->execute();
        $row = $stmt->fetch();

        self::assertNotFalse($row);
        self::assertSame('rbac.role.assign', $row['action']);
        self::assertSame('registration', $row['reason']);
        self::assertSame('system', $row['actor_type']);
        self::assertNull($row['actor_user_id']);

        $newValue = json_decode((string) $row['new_value'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($userId, $newValue['user_id']);
        self::assertSame(RoleKeys::APPLICANT, $newValue['role_key']);
        self::assertSame(1, $newValue['current_marker']);
    }

    public function testBinderIsNotRegisteredAsAPublicHttpRoute(): void
    {
        $router = ApplicationFactory::container('local')->get(Router::class);

        foreach ($router->getRoutes() as $route) {
            $handlerProperty = new \ReflectionProperty($route, 'handler');
            $handlerProperty->setAccessible(true);
            $handler = $handlerProperty->getValue($route);

            $handlerClass = null;
            if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
                $handlerClass = $handler[0];
            } elseif (is_array($handler) && isset($handler[0]) && is_object($handler[0])) {
                $handlerClass = $handler[0]::class;
            } elseif (is_string($handler)) {
                $handlerClass = $handler;
            }

            self::assertNotSame(
                InitialApplicantRoleBinder::class,
                $handlerClass,
                sprintf(
                    'Route %s must not expose InitialApplicantRoleBinder directly as an HTTP handler.',
                    $route->getPath(),
                ),
            );
        }

        // The only production HTTP entry point that reaches the binder is the registration
        // flow, which delegates to RegistrationService (never the binder) as its route handler.
        $registerRoute = null;
        foreach ($router->getRoutes() as $route) {
            if ($route->getPath() === '/register' && in_array('POST', (array) $route->getMethod(), true)) {
                $registerRoute = $route;
                break;
            }
        }
        self::assertNotNull($registerRoute, 'Expected POST /register to be registered.');

        $handlerProperty = new \ReflectionProperty($registerRoute, 'handler');
        $handlerProperty->setAccessible(true);
        $handler = $handlerProperty->getValue($registerRoute);
        self::assertSame(RegistrationController::class, $handler[0]);
    }

    private function insertRawPendingUser(\PDO $pdo, string $email): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('binder-fixture-password-123', PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                ?, NULL, ?, NULL, ?,
                ?, 0, NULL, 1,
                ?, ?, ?,
                ?, ?, ?, ?, ?
            )',
        );
        $stmt->execute([
            strtolower($email),
            '+91' . random_int(6000000000, 9999999999),
            $hash,
            AccountStatus::PENDING_VERIFICATION,
            $now,
            $now,
            'binder.test.terms.v0',
            $now,
            'binder.test.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
