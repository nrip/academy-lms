<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\MobileOtpResendService;
use Academy\Application\Identity\MobileOtpVerificationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class MobileOtpVerificationTest extends TestCase
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

    public function testSuccessSetsMobileVerifiedAtAndAccountStatusUnchanged(): void
    {
        [$userId, $mobile] = $this->insertRawUser('otpsuccess');
        $container = ApplicationFactory::container('testing');
        /** @var VerificationChallengeIssuer $issuer */
        $issuer = $container->get(VerificationChallengeIssuer::class);
        $issued = $issuer->issue($userId, $mobile, new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')));

        /** @var MobileOtpVerificationService $verification */
        $verification = $container->get(MobileOtpVerificationService::class);
        $verification->verify($userId, null, $issued['otp'], '198.51.100.30');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT account_status, mobile_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        self::assertNotNull($row['mobile_verified_at']);
        self::assertSame(AccountStatus::PENDING_VERIFICATION, $row['account_status']);
    }

    public function testWrongOtpThrowsDomainRuleException(): void
    {
        [$userId, $mobile] = $this->insertRawUser('otpwrong');
        $container = ApplicationFactory::container('testing');
        /** @var VerificationChallengeIssuer $issuer */
        $issuer = $container->get(VerificationChallengeIssuer::class);
        $issuer->issue($userId, $mobile, new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')));

        /** @var MobileOtpVerificationService $verification */
        $verification = $container->get(MobileOtpVerificationService::class);

        $this->expectException(DomainRuleException::class);
        $verification->verify($userId, null, '000000', '198.51.100.31');
    }

    public function testExpiredChallengeThrowsDomainRuleException(): void
    {
        [$userId, $mobile] = $this->insertRawUser('otpexpired');
        $container = ApplicationFactory::container('testing');
        /** @var VerificationChallengeIssuer $issuer */
        $issuer = $container->get(VerificationChallengeIssuer::class);
        $issued = $issuer->issue($userId, $mobile, new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')));

        $pdo = DatabaseTestCase::pdo();
        $past = (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo->prepare('UPDATE verification_challenges SET expires_at = ? WHERE verification_challenge_id = ?')
            ->execute([$past, $issued['verification_challenge_id']]);

        /** @var MobileOtpVerificationService $verification */
        $verification = $container->get(MobileOtpVerificationService::class);

        $this->expectException(DomainRuleException::class);
        $verification->verify($userId, null, $issued['otp'], '198.51.100.32');
    }

    public function testMaxAttemptsExceededThrowsDomainRuleException(): void
    {
        [$userId, $mobile] = $this->insertRawUser('otpmaxattempts');
        $container = ApplicationFactory::container('testing');
        /** @var VerificationChallengeIssuer $issuer */
        $issuer = $container->get(VerificationChallengeIssuer::class);
        $issued = $issuer->issue($userId, $mobile, new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')), 1);

        /** @var MobileOtpVerificationService $verification */
        $verification = $container->get(MobileOtpVerificationService::class);

        try {
            $verification->verify($userId, null, '000000', '198.51.100.33');
            self::fail('Expected the first (wrong) attempt to fail.');
        } catch (DomainRuleException) {
            // expected: wrong OTP consumes the single allowed attempt
        }

        $this->expectException(DomainRuleException::class);
        $verification->verify($userId, null, $issued['otp'], '198.51.100.33');
    }

    public function testSmsUnavailableOnResendReturnsServiceUnavailableAndCreatesNoNewChallenge(): void
    {
        [$userId] = $this->insertRawUser('otpresendunavailable');
        $container = ApplicationFactory::container('testing');

        $pdo = DatabaseTestCase::pdo();
        $before = $pdo->prepare('SELECT COUNT(*) FROM verification_challenges WHERE user_id = ?');
        $before->execute([$userId]);
        self::assertSame(0, (int) $before->fetchColumn());

        $resend = new MobileOtpResendService(
            $container->get(TransactionManager::class),
            $container->get(UserWriteRepository::class),
            $container->get(VerificationChallengeIssuer::class),
            $container->get(\Academy\Application\Audit\AuditService::class),
            NotificationCapability::fromEnvFlags(true, false),
            $container->get(\Academy\Application\Security\RateLimiter::class),
            $container->get(\Academy\Application\Security\RateLimitKeyFactory::class),
        );

        try {
            $resend->resend($userId, null, '198.51.100.34');
            self::fail('Expected ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
            // expected
        }

        $after = $pdo->prepare('SELECT COUNT(*) FROM verification_challenges WHERE user_id = ?');
        $after->execute([$userId]);
        self::assertSame(0, (int) $after->fetchColumn());
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function insertRawUser(string $label): array
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('otp-fixture-password-1', PASSWORD_ARGON2ID);
        $mobile = '+91' . random_int(6000000000, 9999999999);
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
            strtolower($label) . '.' . bin2hex(random_bytes(4)) . '@example.test',
            $mobile,
            $hash,
            AccountStatus::PENDING_VERIFICATION,
            $now,
            $now,
            'otp.test.terms.v0',
            $now,
            'otp.test.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);

        return [(int) $pdo->lastInsertId(), $mobile];
    }
}
