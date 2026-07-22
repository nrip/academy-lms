<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\EmailVerificationTokenConsumedHandler;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;

final class EmailVerificationActivationTest extends TestCase
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

    public function testConsumeEmailTokenActivatesPendingUser(): void
    {
        $userId = $this->insertRawUser('activate.' . bin2hex(random_bytes(4)) . '@example.test', AccountStatus::PENDING_VERIFICATION);
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $issued = $issuer->issue($userId, TokenPurpose::EMAIL_VERIFY, 'activate@example.test', new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));

        $this->confirm($container, $issued['raw_token']);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        self::assertNotNull($row['email_verified_at']);
        self::assertSame(AccountStatus::ACTIVE, $row['account_status']);
    }

    public function testSuspendedUserGetsEmailVerifiedButAccountStatusStaysSuspended(): void
    {
        $userId = $this->insertRawUser('suspended.' . bin2hex(random_bytes(4)) . '@example.test', AccountStatus::SUSPENDED);
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $issued = $issuer->issue($userId, TokenPurpose::EMAIL_VERIFY, 'suspended@example.test', new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));

        $this->confirm($container, $issued['raw_token']);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        self::assertNotNull($row['email_verified_at']);
        self::assertSame(AccountStatus::SUSPENDED, $row['account_status']);
    }

    public function testIdempotentSecondConsumeDoesNotChangeAlreadyVerifiedState(): void
    {
        $userId = $this->insertRawUser('idempotent.' . bin2hex(random_bytes(4)) . '@example.test', AccountStatus::PENDING_VERIFICATION);
        $container = ApplicationFactory::container('testing');
        /** @var UserWriteRepository $users */
        $users = $container->get(UserWriteRepository::class);
        /** @var \Academy\Application\Audit\AuditService $audit */
        $audit = $container->get(\Academy\Application\Audit\AuditService::class);
        $handler = new EmailVerificationTokenConsumedHandler($users, $audit);

        $handler->onConsumed($userId, TokenPurpose::EMAIL_VERIFY, 1);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $firstRow = $stmt->fetch();
        self::assertNotNull($firstRow['email_verified_at']);
        self::assertSame(AccountStatus::ACTIVE, $firstRow['account_status']);

        // Second application of the same activation logic must be a strict no-op.
        $handler->onConsumed($userId, TokenPurpose::EMAIL_VERIFY, 2);

        $stmt->execute([$userId]);
        $secondRow = $stmt->fetch();
        self::assertSame($firstRow['email_verified_at'], $secondRow['email_verified_at']);
        self::assertSame(AccountStatus::ACTIVE, $secondRow['account_status']);
    }

    public function testReplayingAnAlreadyConsumedConfirmationSecretIsRejected(): void
    {
        $userId = $this->insertRawUser('replay.' . bin2hex(random_bytes(4)) . '@example.test', AccountStatus::PENDING_VERIFICATION);
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $issued = $issuer->issue($userId, TokenPurpose::EMAIL_VERIFY, 'replay@example.test', new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));

        /** @var TokenConfirmationService $confirmations */
        $confirmations = $container->get(TokenConfirmationService::class);
        $begin = $confirmations->beginConfirmationFromRawToken($issued['raw_token'], TokenPurpose::EMAIL_VERIFY, '198.51.100.20', 900);
        self::assertSame('ok', $begin['status']);
        $secret = $begin['confirmation_secret'];

        $confirmations->confirm($secret, TokenPurpose::EMAIL_VERIFY);

        $this->expectException(DomainRuleException::class);
        $confirmations->confirm($secret, TokenPurpose::EMAIL_VERIFY);
    }

    private function confirm(\Psr\Container\ContainerInterface $container, string $rawToken): void
    {
        /** @var TokenConfirmationService $confirmations */
        $confirmations = $container->get(TokenConfirmationService::class);
        $begin = $confirmations->beginConfirmationFromRawToken($rawToken, TokenPurpose::EMAIL_VERIFY, '198.51.100.21', 900);
        self::assertSame('ok', $begin['status']);
        $confirmations->confirm($begin['confirmation_secret'], TokenPurpose::EMAIL_VERIFY);
    }

    private function insertRawUser(string $email, string $accountStatus): int
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('activation-fixture-password-1', PASSWORD_ARGON2ID);
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
            $accountStatus,
            $now,
            $now,
            'activation.test.terms.v0',
            $now,
            'activation.test.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
