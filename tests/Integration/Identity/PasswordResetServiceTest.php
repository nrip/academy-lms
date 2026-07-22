<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Identity\ForgotPasswordService;
use Academy\Application\Identity\PasswordResetService;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Security\SessionService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PasswordResetServiceTest extends TestCase
{
    private string $password = 'a-strong-reset-password-1';

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

    public function testForgotPasswordGenericAndIssuesTokenForEligibleUser(): void
    {
        $email = 'reset.req.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = DatabaseTestCase::createSyntheticUser($email, '9' . random_int(100000000, 999999999));
        $container = ApplicationFactory::container('testing');
        /** @var ForgotPasswordService $forgot */
        $forgot = $container->get(ForgotPasswordService::class);

        $forgot->request($email, '203.0.113.20');
        $forgot->request('missing.' . bin2hex(random_bytes(3)) . '@example.test', '203.0.113.20');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM verification_tokens WHERE user_id = ? AND purpose = ? AND current_marker = 1',
        );
        $stmt->execute([$user['user_id'], TokenPurpose::PASSWORD_RESET]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSuspendedUserDoesNotReceiveResetToken(): void
    {
        $email = 'reset.susp.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = DatabaseTestCase::createSyntheticUser(
            $email,
            '9' . random_int(100000000, 999999999),
            accountStatus: AccountStatus::SUSPENDED,
        );
        $container = ApplicationFactory::container('testing');
        /** @var ForgotPasswordService $forgot */
        $forgot = $container->get(ForgotPasswordService::class);
        $forgot->request($email, '203.0.113.21');

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM verification_tokens WHERE user_id = ? AND purpose = ?',
        );
        $stmt->execute([$user['user_id'], TokenPurpose::PASSWORD_RESET]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testResetConsumesOnceUpdatesPasswordIncrementsAuthVersionRevokesSessions(): void
    {
        $email = 'reset.ok.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = DatabaseTestCase::createSyntheticUser($email, '9' . random_int(100000000, 999999999));
        $container = ApplicationFactory::container('testing');
        /** @var SessionService $sessions */
        $sessions = $container->get(SessionService::class);
        $boundA = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        $boundB = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);

        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $issued = $issuer->issue($user['user_id'], TokenPurpose::PASSWORD_RESET, $email, $now->modify('+1 hour'));

        /** @var TokenConfirmationService $confirmations */
        $confirmations = $container->get(TokenConfirmationService::class);
        $begun = $confirmations->beginConfirmationFromRawToken(
            $issued['raw_token'],
            TokenPurpose::PASSWORD_RESET,
            '203.0.113.22',
            900,
        );
        self::assertSame('ok', $begun['status']);

        /** @var PasswordResetService $reset */
        $reset = $container->get(PasswordResetService::class);
        $confirmed = $reset->confirm($begun['confirmation_secret']);
        $newPassword = 'a-brand-new-password-99';
        $reset->complete($confirmed['authorization_secret'], $newPassword, '203.0.113.22');

        $pdo = DatabaseTestCase::pdo();
        $userRow = $pdo->prepare('SELECT password_hash, auth_version FROM users WHERE user_id = ?');
        $userRow->execute([$user['user_id']]);
        $row = $userRow->fetch();
        self::assertTrue(password_verify($newPassword, (string) $row['password_hash']));
        self::assertSame(2, (int) $row['auth_version']);

        $tokenRow = $pdo->prepare('SELECT consumed_at FROM verification_tokens WHERE verification_token_id = ?');
        $tokenRow->execute([$issued['verification_token_id']]);
        self::assertNotNull($tokenRow->fetchColumn());

        $authRow = $pdo->prepare(
            'SELECT consumed_at FROM password_reset_authorizations WHERE user_id = ? ORDER BY password_reset_authorization_id DESC LIMIT 1',
        );
        $authRow->execute([$user['user_id']]);
        self::assertNotNull($authRow->fetchColumn());

        $sessionsLeft = $pdo->prepare(
            'SELECT COUNT(*) FROM sessions WHERE user_id = ? AND revoked_at IS NULL',
        );
        $sessionsLeft->execute([$user['user_id']]);
        self::assertSame(0, (int) $sessionsLeft->fetchColumn());

        // Idempotent replay
        $reset->complete($confirmed['authorization_secret'], $newPassword, '203.0.113.22');
        self::assertSame(2, DatabaseTestCase::authVersion($user['user_id']));

        unset($boundA, $boundB, $sessions);
    }

    public function testRollbackLeavesPasswordAndSessionsIntact(): void
    {
        $email = 'reset.rollback.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = DatabaseTestCase::createSyntheticUser($email, '9' . random_int(100000000, 999999999));
        DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        $container = ApplicationFactory::container('testing');
        /** @var PasswordResetService $reset */
        $reset = $container->get(PasswordResetService::class);

        try {
            $reset->complete(str_repeat('a', 64), 'a-brand-new-password-88', '203.0.113.23');
            self::fail('Expected DomainRuleException');
        } catch (\Academy\Domain\Exception\DomainRuleException) {
            self::assertTrue(true);
        }

        self::assertSame(1, DatabaseTestCase::authVersion($user['user_id']));
        $pdo = DatabaseTestCase::pdo();
        $active = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ? AND revoked_at IS NULL');
        $active->execute([$user['user_id']]);
        self::assertSame(1, (int) $active->fetchColumn());
    }
}
