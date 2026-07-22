<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Identity\PasswordResetService;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LoginResetConcurrencyTest extends TestCase
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

    public function testConcurrentFailedLoginsDoNotLoseIncrementsAndProduceOneLockout(): void
    {
        $email = 'conc.fail.' . bin2hex(random_bytes(3)) . '@example.test';
        $password = 'a-strong-conc-password-1';
        $userId = $this->createActiveUser($email, $password);
        $worker = dirname(__DIR__, 2) . '/Support/login_fail_worker.php';
        $ip = '198.51.100.80';

        $procs = [];
        $pipes = [];
        for ($i = 0; $i < 5; ++$i) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $worker, $email, $ip, (string) $i],
                $descriptors,
                $procPipes,
                dirname(__DIR__, 3),
            );
            self::assertIsResource($proc);
            fclose($procPipes[0]);
            $procs[] = $proc;
            $pipes[] = $procPipes;
        }

        foreach ($procs as $index => $proc) {
            $stdout = stream_get_contents($pipes[$index][1]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            $status = proc_close($proc);
            self::assertSame(0, $status, (string) $stdout);
            self::assertStringContainsString('failed', (string) $stdout);
        }

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT failed_login_count, locked_until FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        self::assertSame(5, (int) $row['failed_login_count']);
        self::assertNotNull($row['locked_until']);
    }

    public function testConcurrentResetSubmissionsProduceOneWinner(): void
    {
        $email = 'conc.reset.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = DatabaseTestCase::createSyntheticUser($email, '9' . random_int(100000000, 999999999));
        DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $issued = $issuer->issue($user['user_id'], TokenPurpose::PASSWORD_RESET, $email, $now->modify('+1 hour'));
        /** @var TokenConfirmationService $confirmations */
        $confirmations = $container->get(TokenConfirmationService::class);
        $begun = $confirmations->beginConfirmationFromRawToken(
            $issued['raw_token'],
            TokenPurpose::PASSWORD_RESET,
            '198.51.100.81',
            900,
        );
        /** @var PasswordResetService $reset */
        $reset = $container->get(PasswordResetService::class);
        $confirmed = $reset->confirm($begun['confirmation_secret']);

        $worker = dirname(__DIR__, 2) . '/Support/reset_complete_worker.php';
        $newPassword = 'a-brand-new-conc-password-1';
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < 4; ++$i) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $worker, $confirmed['authorization_secret'], $newPassword, '198.51.100.81'],
                $descriptors,
                $procPipes,
                dirname(__DIR__, 3),
            );
            self::assertIsResource($proc);
            fclose($procPipes[0]);
            $procs[] = $proc;
            $pipes[] = $procPipes;
        }

        $completed = 0;
        foreach ($procs as $index => $proc) {
            $stdout = trim((string) stream_get_contents($pipes[$index][1]));
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            $status = proc_close($proc);
            self::assertSame(0, $status, $stdout);
            if ($stdout === 'completed') {
                ++$completed;
            }
        }
        self::assertGreaterThanOrEqual(1, $completed);
        self::assertSame(2, DatabaseTestCase::authVersion($user['user_id']));

        $pdo = DatabaseTestCase::pdo();
        $hash = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $hash->execute([$user['user_id']]);
        self::assertTrue(password_verify($newPassword, (string) $hash->fetchColumn()));

        $active = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ? AND revoked_at IS NULL');
        $active->execute([$user['user_id']]);
        self::assertSame(0, (int) $active->fetchColumn());
    }

    private function createActiveUser(string $email, string $password): int
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
            strtolower($email), $now, $mobile, $now, $hash, AccountStatus::ACTIVE,
            $now, $now, 'terms.v1', $now, 'privacy.v1', 'Asia/Kolkata', $now, $now,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
