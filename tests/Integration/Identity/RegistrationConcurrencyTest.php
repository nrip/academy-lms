<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\RegistrationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Real multi-process concurrency proofs for WP-01B-2b registration/verification, following
 * the TokenConfirmationConcurrencyTest pattern.
 */
final class RegistrationConcurrencyTest extends TestCase
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

    public function testConcurrentDuplicateRegistrationsProduceAtMostOneUser(): void
    {
        $email = 'concreg.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);
        $worker = dirname(__DIR__, 2) . '/Support/registration_worker.php';

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, $email, $mobile, 'a-strong-password-conc-1', '203.0.113.70'],
            [PHP_BINARY, $worker, $email, $mobile, 'a-strong-password-conc-2', '203.0.113.71'],
        ]);

        $created = array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'created:')));
        $duplicates = array_values(array_filter($results, static fn (string $r): bool => $r === 'duplicate'));

        self::assertLessThanOrEqual(1, count($created));
        self::assertSame(2, count($created) + count($duplicates));

        $pdo = DatabaseTestCase::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute([strtolower($email)]);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testConcurrentEmailConfirmationPostsHaveExactlyOneWinner(): void
    {
        $issued = $this->issueEmailToken();
        $getWorker = dirname(__DIR__, 2) . '/Support/token_confirm_get_worker.php';
        $postWorker = dirname(__DIR__, 2) . '/Support/token_confirm_post_worker.php';

        $gets = $this->runWorkers([
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.72'],
            [PHP_BINARY, $getWorker, $issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '203.0.113.73'],
        ]);
        $secrets = [];
        foreach ($gets as $line) {
            self::assertStringStartsWith('ok:', $line);
            $secrets[] = substr($line, 3);
        }

        $posts = $this->runWorkers([
            [PHP_BINARY, $postWorker, $secrets[0], TokenPurpose::EMAIL_VERIFY],
            [PHP_BINARY, $postWorker, $secrets[1], TokenPurpose::EMAIL_VERIFY],
        ]);
        sort($posts);
        self::assertSame(['conflict', 'ok'], $posts);

        $pdo = DatabaseTestCase::pdo();
        $user = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE user_id = ?');
        $user->execute([$issued['user_id']]);
        $row = $user->fetch();
        self::assertNotNull($row['email_verified_at']);
        self::assertSame('active', $row['account_status']);
    }

    public function testConcurrentOtpVerifiesHaveExactlyOneWinner(): void
    {
        [$userId, $otp] = $this->issueMobileChallenge();
        $worker = dirname(__DIR__, 2) . '/Support/otp_verify_worker.php';

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, (string) $userId, $otp, '203.0.113.74'],
            [PHP_BINARY, $worker, (string) $userId, $otp, '203.0.113.75'],
        ]);
        sort($results);
        self::assertSame(['conflict', 'ok'], $results);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT mobile_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        self::assertNotNull($stmt->fetchColumn());
    }

    public function testConcurrentOtpResendsLeaveExactlyOneCurrentChallenge(): void
    {
        [$userId] = $this->issueMobileChallenge();
        $worker = dirname(__DIR__, 2) . '/Support/otp_resend_worker.php';

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, (string) $userId, '203.0.113.76'],
            [PHP_BINARY, $worker, (string) $userId, '203.0.113.77'],
        ]);
        // The 60s per-mobile cooldown policy allows only one resend per window: the other
        // concurrent attempt must be cleanly rate-limited, never silently duplicated.
        sort($results);
        self::assertContains('ok', $results);
        foreach ($results as $result) {
            self::assertContains($result, ['ok', 'rate_limited']);
        }

        $pdo = DatabaseTestCase::pdo();
        $current = $pdo->prepare('SELECT COUNT(*) FROM verification_challenges WHERE user_id = ? AND current_marker = 1');
        $current->execute([$userId]);
        self::assertSame(1, (int) $current->fetchColumn());
    }

    public function testConcurrentActivationAttemptsAreIdempotentUnderRealRowLocking(): void
    {
        $userId = $this->insertRawPendingUser();
        $worker = dirname(__DIR__, 2) . '/Support/email_activation_race_worker.php';

        $results = $this->runWorkers([
            [PHP_BINARY, $worker, (string) $userId],
            [PHP_BINARY, $worker, (string) $userId],
        ]);
        sort($results);

        // Exactly one process observes the transition (email_was_null -> activated=1); the
        // other finds it already verified and is a safe, idempotent no-op (activated=0).
        self::assertSame(['activated:0', 'activated:1'], $results);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        self::assertNotNull($row['email_verified_at']);
        self::assertSame('active', $row['account_status']);
    }

    public function testForcedMidTransactionFailureLeavesNoPartialRegistrationState(): void
    {
        $container = ApplicationFactory::container('testing');
        $failingLearnerProfiles = new class () implements LearnerProfileRepository {
            public function insertStub(int $userId, \DateTimeImmutable $now): int
            {
                throw new RuntimeException('forced mid-transaction failure (concurrency suite)');
            }
        };

        $service = new RegistrationService(
            $container->get(\Academy\Infrastructure\Database\TransactionManager::class),
            $container->get(\Academy\Domain\Identity\UserWriteRepository::class),
            $failingLearnerProfiles,
            $container->get(\Academy\Application\Identity\InitialApplicantRoleBinder::class),
            $container->get(VerificationTokenIssuer::class),
            $container->get(VerificationChallengeIssuer::class),
            $container->get(\Academy\Application\Audit\AuditService::class),
            \Academy\Application\Notifications\NotificationCapability::fromEnvFlags(true, true),
            $container->get(\Academy\Application\Security\RateLimiter::class),
            $container->get(\Academy\Domain\Identity\LegalAcceptancePolicy::class),
            $container->get(\Academy\Application\Identity\PasswordHasher::class),
        );

        $email = 'concforcedfail.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        try {
            $service->register($email, $mobile, 'a-strong-password-conc-3', true, true, '203.0.113.78');
            self::fail('Expected the forced failure to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced mid-transaction failure (concurrency suite)', $exception->getMessage());
        }

        $pdo = DatabaseTestCase::pdo();
        $userCount = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $userCount->execute([strtolower($email)]);
        self::assertSame(0, (int) $userCount->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM learner_profiles')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM verification_tokens')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM verification_challenges')->fetchColumn());
    }

    /**
     * @return array{verification_token_id: int, raw_token: string, user_id: int}
     */
    private function issueEmailToken(): array
    {
        $userId = $this->insertRawPendingUser();
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $issued = $issuer->issue(
            $userId,
            TokenPurpose::EMAIL_VERIFY,
            'concurrency-email@example.test',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );

        return $issued + ['user_id' => $userId];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function issueMobileChallenge(): array
    {
        $userId = $this->insertRawPendingUser();
        $mobile = $this->mobileForUser($userId);
        $container = ApplicationFactory::container('testing');
        /** @var VerificationChallengeIssuer $issuer */
        $issuer = $container->get(VerificationChallengeIssuer::class);
        $issued = $issuer->issue($userId, $mobile, new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')));

        return [$userId, $issued['otp']];
    }

    private function mobileForUser(int $userId): string
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT mobile_e164 FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }

    private function insertRawPendingUser(): int
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('concurrency-fixture-password-1', PASSWORD_ARGON2ID);
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
            'concurrency.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            $hash,
            \Academy\Domain\Identity\AccountStatus::PENDING_VERIFICATION,
            $now,
            $now,
            'concurrency.test.terms.v0',
            $now,
            'concurrency.test.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<list<string>> $commands
     * @return list<string>
     */
    private function runWorkers(array $commands): array
    {
        $env = [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'academy_lms_test',
            'DB_USER' => getenv('DB_USER') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
            'APP_ENV' => 'testing',
            'TOKEN_PEPPER' => $_ENV['TOKEN_PEPPER'] ?? 'testing-token-pepper-not-for-production',
            'OTP_PEPPER' => $_ENV['OTP_PEPPER'] ?? 'testing-otp-pepper-not-for-production',
            'RATE_LIMIT_PEPPER' => $_ENV['RATE_LIMIT_PEPPER'] ?? 'phpunit-rate-limit-pepper',
            'NOTIFICATION_DELIVERY_KEY' => $_ENV['NOTIFICATION_DELIVERY_KEY']
                ?? 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ];

        $processes = [];
        $pipesList = [];
        foreach ($commands as $command) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($command, $descriptors, $pipes, null, $env);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $processes[] = $proc;
            $pipesList[] = $pipes;
        }

        $results = [];
        foreach ($processes as $index => $proc) {
            $stdout = stream_get_contents($pipesList[$index][1]);
            $stderr = stream_get_contents($pipesList[$index][2]);
            fclose($pipesList[$index][1]);
            fclose($pipesList[$index][2]);
            $status = proc_close($proc);
            self::assertSame(0, $status, 'Worker failed: ' . $stderr . ' / ' . $stdout);
            $results[] = trim((string) $stdout);
        }

        return $results;
    }
}
