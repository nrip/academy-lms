<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\LoginService;
use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LoginServiceTest extends TestCase
{
    private string $password = 'a-strong-login-password-1';

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

    public function testSuccessfulLoginClearsCountersAndBindsSession(): void
    {
        $email = 'login.ok.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = $this->createActiveUser($email, $this->password);
        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);
        /** @var SessionService $sessions */
        $sessions = $container->get(SessionService::class);

        $success = $login->authenticate($email, $this->password, '203.0.113.10');
        self::assertSame($user['user_id'], $success->userId);

        $loaded = $sessions->loadOrCreate(null, '203.0.113.10', 'phpunit');
        $established = $login->establishSession($sessions, $loaded['record'], $success);
        self::assertSame($user['user_id'], $established['record']->userId);
        self::assertSame($user['auth_version'], $established['record']->authVersion);
        self::assertArrayNotHasKey('pending_verification_user_id', $established['record']->payload);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT failed_login_count, locked_until, last_login_at FROM users WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $row = $stmt->fetch();
        self::assertSame(0, (int) $row['failed_login_count']);
        self::assertNull($row['locked_until']);
        self::assertNotNull($row['last_login_at']);
    }

    public function testWrongPasswordIncrementsAndFiveFailuresLock(): void
    {
        $email = 'login.lock.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = $this->createActiveUser($email, $this->password);
        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);

        for ($i = 1; $i <= 5; ++$i) {
            try {
                $login->authenticate($email, 'wrong-password-xxxxx', '203.0.113.11');
                self::fail('Expected AuthenticationException');
            } catch (AuthenticationException $exception) {
                self::assertSame(LoginService::GENERIC_FAILURE, $exception->getMessage());
            }
        }

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT failed_login_count, locked_until FROM users WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $row = $stmt->fetch();
        self::assertSame(5, (int) $row['failed_login_count']);
        self::assertNotNull($row['locked_until']);

        try {
            $login->authenticate($email, $this->password, '203.0.113.11');
            self::fail('Locked account must not authenticate');
        } catch (AuthenticationException) {
            self::assertTrue(true);
        }
    }

    public function testLockExpiryAllowsLoginAgain(): void
    {
        $email = 'login.expiry.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = $this->createActiveUser($email, $this->password);
        $pdo = DatabaseTestCase::pdo();
        $past = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 minute')->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'UPDATE users SET failed_login_count = 5, locked_until = ?, failed_login_window_started_at = ? WHERE user_id = ?',
        )->execute([$past, $past, $user['user_id']]);

        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);
        $success = $login->authenticate($email, $this->password, '203.0.113.12');
        self::assertSame($user['user_id'], $success->userId);
    }

    public function testSuspendedAndPendingDenied(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);

        $pendingEmail = 'login.pending.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUser($pendingEmail, $this->password, AccountStatus::PENDING_VERIFICATION, false);
        try {
            $login->authenticate($pendingEmail, $this->password, '203.0.113.13');
            self::fail('Pending must not authenticate');
        } catch (AuthenticationException) {
            self::assertTrue(true);
        }

        $suspendedEmail = 'login.susp.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUser($suspendedEmail, $this->password, AccountStatus::SUSPENDED, true);
        try {
            $login->authenticate($suspendedEmail, $this->password, '203.0.113.13');
            self::fail('Suspended must not authenticate');
        } catch (AuthenticationException) {
            self::assertTrue(true);
        }
    }

    public function testUnknownEmailUsesDummyVerifyAndGenericFailure(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);
        try {
            $login->authenticate('missing.' . bin2hex(random_bytes(3)) . '@example.test', 'any-password-xx', '203.0.113.14');
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $exception) {
            self::assertSame(LoginService::GENERIC_FAILURE, $exception->getMessage());
        }
    }

    public function testPasswordRehashOnSuccessfulLogin(): void
    {
        $email = 'login.rehash.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = $this->createActiveUser($email, $this->password);
        // Force an outdated Argon2id cost so needs_rehash returns true.
        $old = password_hash($this->password, PASSWORD_ARGON2ID, ['memory_cost' => 16384, 'time_cost' => 2, 'threads' => 1]);
        DatabaseTestCase::pdo()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([$old, $user['user_id']]);

        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);
        $success = $login->authenticate($email, $this->password, '203.0.113.15');
        self::assertTrue($success->passwordRehashed);

        $hash = DatabaseTestCase::pdo()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $hash->execute([$user['user_id']]);
        $stored = (string) $hash->fetchColumn();
        self::assertNotSame($old, $stored);
        self::assertTrue(password_verify($this->password, $stored));
    }

    /**
     * @return array{user_id: int, auth_version: int}
     */
    private function createActiveUser(string $email, string $password): array
    {
        return $this->createUser($email, $password, AccountStatus::ACTIVE, true);
    }

    /**
     * @return array{user_id: int, auth_version: int}
     */
    private function createUser(string $email, string $password, string $status, bool $emailVerified): array
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = (new PasswordHasher())->hash($password);
        $mobile = '9' . random_int(100000000, 999999999);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 0, NULL, 1,
                ?, ?, ?,
                ?, ?, ?, ?, ?
            )',
        );
        $stmt->execute([
            strtolower($email),
            $emailVerified ? $now : null,
            $mobile,
            $now,
            $hash,
            $status,
            $now,
            $now,
            'terms.v1',
            $now,
            'privacy.v1',
            'Asia/Kolkata',
            $now,
            $now,
        ]);

        return ['user_id' => (int) $pdo->lastInsertId(), 'auth_version' => 1];
    }
}
