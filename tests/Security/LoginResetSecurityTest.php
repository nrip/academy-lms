<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Identity\LoginService;
use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Domain\Identity\AccountStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LoginResetSecurityTest extends TestCase
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

    public function testNoAccountEnumerationAcrossUnknownWrongPendingSuspended(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);
        $password = 'a-strong-security-password-1';

        $pending = 'sec.pending.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUser($pending, $password, AccountStatus::PENDING_VERIFICATION, false);
        $suspended = 'sec.susp.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUser($suspended, $password, AccountStatus::SUSPENDED, true);
        $active = 'sec.active.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createUser($active, $password, AccountStatus::ACTIVE, true);

        $messages = [];
        foreach ([
            ['missing.' . bin2hex(random_bytes(3)) . '@example.test', $password],
            [$active, 'wrong-password-xxxxx'],
            [$pending, $password],
            [$suspended, $password],
        ] as [$email, $pwd]) {
            try {
                $login->authenticate($email, $pwd, '198.51.100.70');
                self::fail('Expected failure');
            } catch (\Academy\Domain\Exception\AuthenticationException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertSame(
            [LoginService::GENERIC_FAILURE, LoginService::GENERIC_FAILURE, LoginService::GENERIC_FAILURE, LoginService::GENERIC_FAILURE],
            $messages,
        );
    }

    public function testRateLimitBucketKeysNeverContainRawEmail(): void
    {
        $pepper = ApplicationFactory::securityConfig('testing')['rate_limit_pepper'];
        $factory = new RateLimitKeyFactory($pepper);
        $email = 'raw.email.' . bin2hex(random_bytes(3)) . '@example.test';
        $key = $factory->bucketKey('auth.login', 'email', $factory->normalizeEmail($email));
        self::assertStringNotContainsString($email, $key);
        self::assertStringNotContainsString(strtolower($email), $key);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
    }

    public function testAuditPayloadsNeverContainSecrets(): void
    {
        $email = 'sec.audit.' . bin2hex(random_bytes(3)) . '@example.test';
        $password = 'a-strong-security-password-2';
        $this->createUser($email, $password, AccountStatus::ACTIVE, true);
        $container = ApplicationFactory::container('testing');
        /** @var LoginService $login */
        $login = $container->get(LoginService::class);
        $login->authenticate($email, $password, '198.51.100.71');

        $pdo = DatabaseTestCase::pdo();
        $rows = $pdo->query(
            "SELECT action, previous_value, new_value FROM audit_log WHERE action LIKE 'identity.login%'",
        )->fetchAll();
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            $blob = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($password, $blob);
            self::assertStringNotContainsString($email, $blob);
            self::assertStringNotContainsString('$argon2id$', $blob);
        }
    }

    private function createUser(string $email, string $password, string $status, bool $verified): void
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = (new PasswordHasher())->hash($password);
        $mobile = '9' . random_int(100000000, 999999999);
        $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, NULL, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            strtolower($email), $verified ? $now : null, $mobile, $now, $hash, $status,
            $now, $now, 'terms.v1', $now, 'privacy.v1', 'Asia/Kolkata', $now, $now,
        ]);
    }
}
