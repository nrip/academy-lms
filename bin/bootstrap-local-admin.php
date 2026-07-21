<?php

declare(strict_types=1);

/**
 * Local/testing-only Super Admin bootstrap.
 *
 * Hard-refuses production and staging. Never prints or logs password, hash, or session tokens.
 * Bootstrap-created privileged sessions must remain mfa_enrolment_required (never fully_authenticated).
 */

use Academy\Application\RBAC\RoleAssignmentService;
use Academy\Application\Security\SessionService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Psr\Container\ContainerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$env = strtolower((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'local')));
$allow = filter_var(getenv('ALLOW_LOCAL_BOOTSTRAP_ADMIN') ?: ($_ENV['ALLOW_LOCAL_BOOTSTRAP_ADMIN'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);

if (in_array($env, ['production', 'staging', 'prod'], true)) {
    fwrite(STDERR, "Bootstrap refused: APP_ENV={$env} is not permitted.\n");
    exit(1);
}

if (!in_array($env, ['local', 'testing'], true) || !$allow) {
    fwrite(STDERR, "Bootstrap refused: require APP_ENV=local|testing and ALLOW_LOCAL_BOOTSTRAP_ADMIN=true.\n");
    exit(1);
}

$email = strtolower(trim((string) (getenv('BOOTSTRAP_ADMIN_EMAIL') ?: ($_ENV['BOOTSTRAP_ADMIN_EMAIL'] ?? ''))));
$mobile = trim((string) (getenv('BOOTSTRAP_ADMIN_MOBILE') ?: ($_ENV['BOOTSTRAP_ADMIN_MOBILE'] ?? '+910000000000')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "BOOTSTRAP_ADMIN_EMAIL is required and must be a valid email.\n");
    exit(1);
}

$hash = (string) (getenv('BOOTSTRAP_ADMIN_PASSWORD_HASH') ?: ($_ENV['BOOTSTRAP_ADMIN_PASSWORD_HASH'] ?? ''));
$plaintext = (string) (getenv('BOOTSTRAP_ADMIN_PASSWORD') ?: ($_ENV['BOOTSTRAP_ADMIN_PASSWORD'] ?? ''));

if ($hash === '' && $plaintext === '') {
    if (!function_exists('readline') && !defined('STDIN')) {
        fwrite(STDERR, "Provide BOOTSTRAP_ADMIN_PASSWORD_HASH or interactive stdin.\n");
        exit(1);
    }
    fwrite(STDERR, "Enter bootstrap admin password (hidden): ");
    $plaintext = readHiddenPassword();
    if ($plaintext === '') {
        fwrite(STDERR, "Password required.\n");
        exit(1);
    }
}

if ($hash === '') {
    // Plaintext password env is only accepted under the local/testing gates already enforced above.
    $hash = password_hash($plaintext, PASSWORD_ARGON2ID);
    // Wipe plaintext from local variable promptly; never log it.
    $plaintext = '';
}

if ($hash === false || $hash === '') {
    fwrite(STDERR, "Failed to prepare password hash.\n");
    exit(1);
}

/** @var ContainerInterface $container */
$container = require $root . '/config/bootstrap.php';

/** @var ConnectionFactory $connections */
$connections = $container->get(ConnectionFactory::class);
/** @var TransactionManager $tx */
$tx = $container->get(TransactionManager::class);
/** @var RoleAssignmentService $roles */
$roles = $container->get(RoleAssignmentService::class);
/** @var SessionService $sessions */
$sessions = $container->get(SessionService::class);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowStr = $now->format('Y-m-d H:i:s.u');
$termsVersion = 'synthetic.local.terms.v0';
$privacyVersion = 'synthetic.local.privacy.v0';

$userId = $tx->run(function (PDO $pdo) use ($email, $mobile, $hash, $nowStr, $termsVersion, $privacyVersion): int {
    $existing = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
    $existing->execute(['email' => $email]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return (int) $row['user_id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (
            email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
            account_status, failed_login_count, locked_until, auth_version,
            password_changed_at, terms_accepted_at, terms_version,
            privacy_accepted_at, privacy_version, email_suppressed_at, timezone,
            created_at, updated_at
        ) VALUES (
            :email, :email_verified_at, :mobile, :mobile_verified_at, :password_hash,
            :status, 0, NULL, 1,
            :password_changed_at, :terms_accepted_at, :terms_version,
            :privacy_accepted_at, :privacy_version, NULL, :timezone,
            :created_at, :updated_at
        )',
    );
    $insert->execute([
        'email' => $email,
        'email_verified_at' => $nowStr,
        'mobile' => $mobile,
        'mobile_verified_at' => $nowStr,
        'password_hash' => $hash,
        'status' => AccountStatus::ACTIVE,
        'password_changed_at' => $nowStr,
        'terms_accepted_at' => $nowStr,
        'terms_version' => $termsVersion,
        'privacy_accepted_at' => $nowStr,
        'privacy_version' => $privacyVersion,
        'timezone' => 'Asia/Kolkata',
        'created_at' => $nowStr,
        'updated_at' => $nowStr,
    ]);

    return (int) $pdo->lastInsertId();
});

// Assign Super Admin if not already current (RoleAssignmentService validates + locks).
try {
    $roles->assign($userId, RoleKeys::SUPER_ADMIN, null, 'local bootstrap');
} catch (Academy\Domain\Exception\ConflictException) {
    // Already assigned — continue.
}

$loaded = $sessions->loadOrCreate(null, '127.0.0.1', 'bootstrap-cli');
$bound = $sessions->bindUser(
    $loaded['record'],
    $userId,
    // Re-read auth_version after possible role assign.
    (static function () use ($connections, $userId): int {
        $pdo = $connections->connection();
        $stmt = $pdo->prepare('SELECT auth_version FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Bootstrap user missing after create.');
        }

        return Academy\Domain\Identity\AuthVersion::fromDatabase($row['auth_version']);
    })(),
    ['auth_stage' => AuthStage::MFA_ENROLMENT_REQUIRED],
);

fwrite(STDOUT, "Bootstrap Super Admin ready.\n");
fwrite(STDOUT, "user_id={$userId}\n");
fwrite(STDOUT, "email={$email}\n");
fwrite(STDOUT, "auth_stage=" . AuthStage::MFA_ENROLMENT_REQUIRED . "\n");
fwrite(STDOUT, "session_id={$bound->sessionId}\n");
// Intentionally omit raw session token / password / hash from output.

exit(0);

function readHiddenPassword(): string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
    }
    $line = fgets(STDIN);
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty echo');
        fwrite(STDERR, "\n");
    }
    if ($line === false) {
        return '';
    }

    return rtrim($line, "\r\n");
}
