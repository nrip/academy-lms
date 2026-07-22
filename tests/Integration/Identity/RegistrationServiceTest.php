<?php

declare(strict_types=1);

namespace Academy\Tests\Integration\Identity;

use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\InitialApplicantRoleBinder;
use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Identity\RegistrationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LegalAcceptancePolicy;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistrationServiceTest extends TestCase
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

    public function testHappyPathCreatesFullRegistrationGraph(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var RegistrationService $service */
        $service = $container->get(RegistrationService::class);

        $email = 'happy.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $result = $service->register($email, $mobile, 'a-strong-password-1', true, true, '198.51.100.1');

        self::assertTrue($result->created);
        self::assertIsInt($result->userId);
        $userId = $result->userId;

        $pdo = DatabaseTestCase::pdo();

        $user = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
        $user->execute([$userId]);
        $userRow = $user->fetch();
        self::assertNotFalse($userRow);
        self::assertSame('pending_verification', $userRow['account_status']);
        self::assertSame(strtolower($email), $userRow['email']);
        self::assertNull($userRow['email_verified_at']);
        self::assertNull($userRow['mobile_verified_at']);

        $profile = $pdo->prepare('SELECT COUNT(*) FROM learner_profiles WHERE user_id = ?');
        $profile->execute([$userId]);
        self::assertSame(1, (int) $profile->fetchColumn());

        $roles = $pdo->prepare(
            'SELECT r.role_key, ur.current_marker FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ?',
        );
        $roles->execute([$userId]);
        $roleRows = $roles->fetchAll();
        self::assertCount(1, $roleRows);
        self::assertSame('applicant', $roleRows[0]['role_key']);
        self::assertSame(1, (int) $roleRows[0]['current_marker']);

        $token = $pdo->prepare(
            'SELECT verification_token_id, purpose, current_marker, delivery_ciphertext
             FROM verification_tokens WHERE user_id = ?',
        );
        $token->execute([$userId]);
        $tokenRow = $token->fetch();
        self::assertNotFalse($tokenRow);
        self::assertSame('email_verify', $tokenRow['purpose']);
        self::assertSame(1, (int) $tokenRow['current_marker']);
        self::assertNotNull($tokenRow['delivery_ciphertext']);

        $challenge = $pdo->prepare(
            'SELECT verification_challenge_id, channel, current_marker, otp_delivery_ciphertext
             FROM verification_challenges WHERE user_id = ?',
        );
        $challenge->execute([$userId]);
        $challengeRow = $challenge->fetch();
        self::assertNotFalse($challengeRow, 'SMS is available in testing; a challenge must be issued.');
        self::assertSame('sms', $challengeRow['channel']);
        self::assertSame(1, (int) $challengeRow['current_marker']);
        self::assertNotNull($challengeRow['otp_delivery_ciphertext']);

        // truncateAllTestTables() in setUp() empties audit_log per test, so every row here
        // originates from this single registration call.
        $actionList = array_column($pdo->query('SELECT action FROM audit_log')->fetchAll(), 'action');
        self::assertContains('identity.registered', $actionList);
        self::assertContains('identity.email_verification_requested', $actionList);
        self::assertContains('identity.mobile_otp_requested', $actionList);
        self::assertContains('rbac.role.assign', $actionList);

        $outbox = $pdo->query("SELECT event_type, payload FROM outbox_messages WHERE event_type LIKE 'identity.%'")->fetchAll();
        $eventTypes = array_column($outbox, 'event_type');
        self::assertContains(IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND, $eventTypes);
        self::assertContains(IdentityNotificationEventTypes::MOBILE_OTP_SEND, $eventTypes);

        foreach ($outbox as $row) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['event_type'] === IdentityNotificationEventTypes::EMAIL_VERIFICATION_SEND) {
                self::assertSame($tokenRow['verification_token_id'], $payload['verification_token_id']);
            }
            if ($row['event_type'] === IdentityNotificationEventTypes::MOBILE_OTP_SEND) {
                self::assertSame($challengeRow['verification_challenge_id'], $payload['verification_challenge_id']);
            }
        }
    }

    public function testEmailOnlyRegistrationSkipsSmsChallengeAndSucceeds(): void
    {
        $container = ApplicationFactory::container('testing');
        $service = $this->buildServiceWithCapability($container, true, false);

        $email = 'emailonly.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $result = $service->register($email, $mobile, 'a-strong-password-2', true, true, '198.51.100.2');

        self::assertTrue($result->created);
        $userId = $result->userId;
        self::assertIsInt($userId);

        $pdo = DatabaseTestCase::pdo();

        $challenge = $pdo->prepare('SELECT COUNT(*) FROM verification_challenges WHERE user_id = ?');
        $challenge->execute([$userId]);
        self::assertSame(0, (int) $challenge->fetchColumn());

        $outbox = $pdo->prepare(
            "SELECT COUNT(*) FROM outbox_messages WHERE event_type = ? AND aggregate_type = 'verification_challenge'",
        );
        $outbox->execute([IdentityNotificationEventTypes::MOBILE_OTP_SEND]);
        self::assertSame(0, (int) $outbox->fetchColumn());

        $user = $pdo->prepare('SELECT mobile_verified_at FROM users WHERE user_id = ?');
        $user->execute([$userId]);
        self::assertNull($user->fetchColumn());

        $token = $pdo->prepare('SELECT COUNT(*) FROM verification_tokens WHERE user_id = ?');
        $token->execute([$userId]);
        self::assertSame(1, (int) $token->fetchColumn());
    }

    public function testEmailUnavailableThrowsServiceUnavailableAndCreatesNoUser(): void
    {
        $container = ApplicationFactory::container('testing');
        $service = $this->buildServiceWithCapability($container, false, true);

        $email = 'emailunavailable.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        try {
            $service->register($email, $mobile, 'a-strong-password-3', true, true, '198.51.100.3');
            self::fail('Expected ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
            // expected
        }

        $pdo = DatabaseTestCase::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute([strtolower($email)]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testDuplicateRegistrationReturnsCreatedFalseAndKeepsOneUserRow(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var RegistrationService $service */
        $service = $container->get(RegistrationService::class);

        $email = 'dup.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $first = $service->register($email, $mobile, 'a-strong-password-4', true, true, '198.51.100.4');
        self::assertTrue($first->created);

        $second = $service->register($email, $mobile, 'a-different-password-5', true, true, '198.51.100.4');
        self::assertFalse($second->created);
        self::assertNull($second->userId);

        $pdo = DatabaseTestCase::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute([strtolower($email)]);
        self::assertSame(1, (int) $count->fetchColumn());

        $profiles = $pdo->prepare('SELECT COUNT(*) FROM learner_profiles WHERE user_id = ?');
        $profiles->execute([$first->userId]);
        self::assertSame(1, (int) $profiles->fetchColumn());
    }

    public function testForcedFailureMidTransactionRollsBackEverything(): void
    {
        $container = ApplicationFactory::container('testing');
        /** @var UserWriteRepository $users */
        $users = $container->get(UserWriteRepository::class);
        /** @var TransactionManager $transactions */
        $transactions = $container->get(TransactionManager::class);
        /** @var InitialApplicantRoleBinder $roleBinder */
        $roleBinder = $container->get(InitialApplicantRoleBinder::class);
        /** @var VerificationTokenIssuer $tokenIssuer */
        $tokenIssuer = $container->get(VerificationTokenIssuer::class);
        /** @var VerificationChallengeIssuer $challengeIssuer */
        $challengeIssuer = $container->get(VerificationChallengeIssuer::class);
        /** @var AuditService $audit */
        $audit = $container->get(AuditService::class);
        /** @var RateLimiter $rateLimiter */
        $rateLimiter = $container->get(RateLimiter::class);
        /** @var LegalAcceptancePolicy $legal */
        $legal = $container->get(LegalAcceptancePolicy::class);
        /** @var PasswordHasher $hasher */
        $hasher = $container->get(PasswordHasher::class);

        $failingLearnerProfiles = new class () implements LearnerProfileRepository {
            public function insertStub(int $userId, \DateTimeImmutable $now): int
            {
                throw new RuntimeException('forced mid-transaction failure');
            }

            public function findByUserId(int $userId): ?\Academy\Domain\Identity\LearnerProfile
            {
                throw new RuntimeException('Not used in this test.');
            }

            public function findById(int $profileId): ?\Academy\Domain\Identity\LearnerProfile
            {
                throw new RuntimeException('Not used in this test.');
            }

            public function updatePersonal(int $profileId, int $expectedVersion, array $fields, \DateTimeImmutable $now): int
            {
                throw new RuntimeException('Not used in this test.');
            }

            public function updateProfessional(int $profileId, int $expectedVersion, array $fields, \DateTimeImmutable $now): int
            {
                throw new RuntimeException('Not used in this test.');
            }
        };

        $service = new RegistrationService(
            $transactions,
            $users,
            $failingLearnerProfiles,
            $roleBinder,
            $tokenIssuer,
            $challengeIssuer,
            $audit,
            NotificationCapability::fromEnvFlags(true, true),
            $rateLimiter,
            $legal,
            $hasher,
        );

        $email = 'forcedfail.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        try {
            $service->register($email, $mobile, 'a-strong-password-6', true, true, '198.51.100.5');
            self::fail('Expected the forced failure to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced mid-transaction failure', $exception->getMessage());
        }

        $pdo = DatabaseTestCase::pdo();
        $userCount = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $userCount->execute([strtolower($email)]);
        self::assertSame(0, (int) $userCount->fetchColumn(), 'The user insert must be rolled back.');

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM learner_profiles')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM verification_tokens')->fetchColumn());
    }

    private function buildServiceWithCapability(
        \Psr\Container\ContainerInterface $container,
        bool $email,
        bool $sms,
    ): RegistrationService {
        return new RegistrationService(
            $container->get(TransactionManager::class),
            $container->get(UserWriteRepository::class),
            $container->get(LearnerProfileRepository::class),
            $container->get(InitialApplicantRoleBinder::class),
            $container->get(VerificationTokenIssuer::class),
            $container->get(VerificationChallengeIssuer::class),
            $container->get(AuditService::class),
            NotificationCapability::fromEnvFlags($email, $sms),
            $container->get(RateLimiter::class),
            $container->get(LegalAcceptancePolicy::class),
            $container->get(PasswordHasher::class),
        );
    }
}
