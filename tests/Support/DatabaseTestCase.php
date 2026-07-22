<?php

declare(strict_types=1);

namespace Academy\Tests\Support;

use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\AuthVersion;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

final class DatabaseTestCase
{
    public static function available(): bool
    {
        try {
            self::pdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function pdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        $probe = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $probe->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        );
    }

    public static function connectionFactory(): ConnectionFactory
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: '3306');
        $name = getenv('DB_NAME') ?: 'academy_lms_test';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

        self::pdo();

        return new ConnectionFactory([
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        ]);
    }

    public static function migrate(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        self::syncDbEnvForPhinx();
        $root = dirname(__DIR__, 2);
        $configArray = require $root . '/phinx.php';
        $configArray['paths']['migrations'] = $root . '/database/migrations';
        $configArray['paths']['seeds'] = $root . '/database/seeds';
        $config = new Config($configArray);
        $manager = new Manager($config, new StringInput(''), new NullOutput());
        $manager->migrate('testing');
    }

    public static function truncateWp01aTables(): void
    {
        self::truncateAllTestTables();
    }

    public static function truncateAllTestTables(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'token_confirmation_contexts',
            'verification_challenges',
            'verification_tokens',
            'sessions',
            'rate_limit_buckets',
            'outbox_messages',
            'scheduler_locks',
            'user_roles',
            'users',
        ] as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        $pdo->exec('DROP TRIGGER IF EXISTS trg_audit_log_forbid_delete');
        $pdo->exec('DELETE FROM audit_log');
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_audit_log_forbid_delete
BEFORE DELETE ON audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'
SQL);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param list<string> $roleKeys
     * @return array{user_id: int, auth_version: int}
     */
    public static function createSyntheticUser(
        string $email,
        string $mobile,
        array $roleKeys = [],
        string $accountStatus = AccountStatus::ACTIVE,
        ?\DateTimeImmutable $lockedUntil = null,
        int $authVersion = 1,
    ): array {
        $pdo = self::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('synthetic-local-password', PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, 0, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?
            )',
        );
        $stmt->execute([
            strtolower($email),
            $now,
            $mobile,
            $now,
            $hash,
            $accountStatus,
            $lockedUntil?->format('Y-m-d H:i:s.u'),
            $authVersion,
            $now,
            $now,
            'synthetic.local.terms.v0',
            $now,
            'synthetic.local.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);
        $userId = (int) $pdo->lastInsertId();

        foreach ($roleKeys as $roleKey) {
            $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = :key');
            $roleStmt->execute(['key' => $roleKey]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if ($role === false) {
                throw new \RuntimeException('Missing seeded role: ' . $roleKey);
            }
            $assign = $pdo->prepare(
                'INSERT INTO user_roles (
                    user_id, role_id, assigned_by, assigned_at, current_marker, created_at, updated_at
                ) VALUES (?, ?, NULL, ?, 1, ?, ?)',
            );
            $assign->execute([
                $userId,
                (int) $role['role_id'],
                $now,
                $now,
                $now,
            ]);
        }

        return ['user_id' => $userId, 'auth_version' => $authVersion];
    }

    public static function roleId(string $roleKey): int
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_key = :key');
        $stmt->execute(['key' => $roleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Role not found: ' . $roleKey);
        }

        return (int) $row['role_id'];
    }

    public static function authVersion(int $userId): int
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare('SELECT auth_version FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('User not found');
        }

        return AuthVersion::fromDatabase($row['auth_version']);
    }

    /**
     * @return array{session: string, csrf: string, record_id: int}
     */
    public static function bindSessionForUser(
        int $userId,
        int $authVersion,
        string $authStage = AuthStage::FULLY_AUTHENTICATED,
    ): array {
        $container = ApplicationFactory::container('testing');
        /** @var \Academy\Application\Security\SessionService $sessions */
        $sessions = $container->get(\Academy\Application\Security\SessionService::class);
        $loaded = $sessions->loadOrCreate(null, '127.0.0.1', 'phpunit');
        $bound = $sessions->bindUser($loaded['record'], $userId, $authVersion, [
            'auth_stage' => $authStage,
        ]);

        return [
            'session' => $loaded['raw_token'],
            'csrf' => $loaded['raw_csrf'],
            'record_id' => $bound->sessionId,
        ];
    }

    public static function applicantFixture(): array
    {
        return self::createSyntheticUser(
            'applicant.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
        );
    }

    public static function financeFixture(): array
    {
        return self::createSyntheticUser(
            'finance.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::FINANCE_ADMIN],
        );
    }

    public static function reviewerFixture(): array
    {
        return self::createSyntheticUser(
            'reviewer.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::CREDENTIAL_REVIEWER],
        );
    }

    public static function superAdminFixture(): array
    {
        return self::createSyntheticUser(
            'super.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::SUPER_ADMIN],
        );
    }

    private static function syncDbEnvForPhinx(): void
    {
        $map = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
        ];
        foreach ($map as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
