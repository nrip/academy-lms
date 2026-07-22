<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Identity;

use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Identity\InitialApplicantRoleBinder;
use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Identity\RegistrationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Domain\Audit\AuditRecord;
use Academy\Domain\Audit\AuditWriter;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LegalAcceptancePolicy;
use Academy\Domain\Identity\OtpHmac;
use Academy\Domain\Identity\TokenHmac;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Identity\VerificationChallengeRecord;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Identity\VerificationTokenRecord;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Notifications\SealedSecret;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Security\RateLimitStore;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Academy\Tests\Support\DatabaseTestCase;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves password hashing happens BEFORE the duplicate-key conflict is observed.
 *
 * PasswordHasher is a final, dependency-free class (no interface), so it cannot be
 * doubled with a call-order-recording mock. Instead this test uses the REAL
 * PasswordHasher and captures the exact hash value handed to UserWriteRepository::
 * insertPendingUser(). Because that value must be a valid Argon2id digest of the
 * submitted plaintext (verified with password_verify()), its presence at the
 * insert call site is structural proof that hashing already completed — a hash of
 * the correct password cannot exist unless PasswordHasher::hash() ran first.
 *
 * All other collaborators are minimal stubs that are never expected to be reached
 * beyond the point of the duplicate-key failure.
 */
final class RegistrationPasswordHashOrderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available (TransactionManager requires a real PDO connection).');
        }
    }

    public function testHashOccursBeforeDuplicateConflictIsSignalled(): void
    {
        $log = new class () {
            /** @var list<string> */
            public array $events = [];
            public ?string $capturedHash = null;
        };

        $password = 'a-strong-password-123';
        $email = 'hashorder.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $users = new class ($log) implements UserWriteRepository {
            public function __construct(
                private object $log,
            ) {
            }

            public function insertPendingUser(
                string $normalizedEmail,
                string $normalizedMobileE164,
                string $passwordHash,
                string $termsVersion,
                string $privacyVersion,
                \DateTimeImmutable $now,
                string $timezone = 'Asia/Kolkata',
            ): int {
                if (str_starts_with($passwordHash, '$argon2id$')) {
                    $this->log->events[] = 'hash';
                }
                $this->log->capturedHash = $passwordHash;
                $this->log->events[] = 'insert_attempted';

                $exception = new PDOException('Duplicate entry for key uq_users_email');
                $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];

                throw $exception;
            }

            public function findById(int $userId): ?array
            {
                throw new \LogicException('Must not be called: duplicate conflict short-circuits registration.');
            }

            public function findByIdForUpdate(int $userId): ?array
            {
                throw new \LogicException('Must not be called.');
            }

            public function findByEmail(string $normalizedEmail): ?array
            {
                throw new \LogicException('Must not be called.');
            }

            public function findByMobileE164(string $normalizedMobileE164): ?array
            {
                throw new \LogicException('Must not be called.');
            }

            public function findCredentialsByEmailForUpdate(string $normalizedEmail): ?array
            {
                throw new \LogicException('Must not be called.');
            }

            public function applyFailedLogin(int $userId, array $state): void
            {
                throw new \LogicException('Must not be called.');
            }

            public function applySuccessfulLogin(int $userId, array $state, ?string $rehashedPassword): void
            {
                throw new \LogicException('Must not be called.');
            }

            public function applyPasswordReset(int $userId, string $passwordHash, \DateTimeImmutable $now): array
            {
                throw new \LogicException('Must not be called.');
            }

            public function applyEmailVerification(int $userId, \DateTimeImmutable $now): array
            {
                throw new \LogicException('Must not be called.');
            }

            public function applyMobileVerification(int $userId, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }
        };

        $learnerProfiles = new class () implements LearnerProfileRepository {
            public function insertStub(int $userId, \DateTimeImmutable $now): int
            {
                throw new \LogicException('Must not be called: duplicate conflict short-circuits registration.');
            }

            public function findByUserId(int $userId): ?\Academy\Domain\Identity\LearnerProfile
            {
                throw new \LogicException('Not used in this test.');
            }

            public function findById(int $profileId): ?\Academy\Domain\Identity\LearnerProfile
            {
                throw new \LogicException('Not used in this test.');
            }

            public function updatePersonal(int $profileId, int $expectedVersion, array $fields, \DateTimeImmutable $now): int
            {
                throw new \LogicException('Not used in this test.');
            }

            public function updateProfessional(int $profileId, int $expectedVersion, array $fields, \DateTimeImmutable $now): int
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        $noopAuditWriter = new class () implements AuditWriter {
            public function append(AuditRecord $record): void
            {
                throw new \LogicException('Must not be called: duplicate conflict short-circuits registration.');
            }
        };
        $audit = new AuditService($noopAuditWriter, new AuditRedactor());

        $rateLimitStore = new class () implements RateLimitStore {
            public function incrementAndGetCount(
                string $bucketKey,
                string $policyKey,
                \DateTimeImmutable $windowStartsAt,
                \DateTimeImmutable $windowEndsAt,
            ): int {
                return 1;
            }

            public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
            {
                return 0;
            }
        };
        $rateLimiter = new RateLimiter(
            $rateLimitStore,
            new RateLimitKeyFactory('registration-order-test-pepper'),
            new NullLogger(),
            [
                'auth.registration' => ['limit' => 1000, 'window_seconds' => 3600, 'failure' => 'fail_closed'],
            ],
        );

        $transactions = new TransactionManager(DatabaseTestCase::connectionFactory());

        $tokenRepository = new class () implements VerificationTokenRepository {
            public function findByHash(string $tokenHash): ?VerificationTokenRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function findById(int $verificationTokenId): ?VerificationTokenRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function findByIdForUpdate(int $verificationTokenId): ?VerificationTokenRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function findCurrentByUserPurposeForUpdate(int $userId, string $purpose): ?VerificationTokenRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function clearCurrentMarker(int $verificationTokenId): void
            {
                throw new \LogicException('Must not be called.');
            }

            public function insertPendingCurrent(
                int $userId,
                string $purpose,
                string $tokenHash,
                \DateTimeImmutable $expiresAt,
                \DateTimeImmutable $now,
            ): int {
                throw new \LogicException('Must not be called: duplicate conflict short-circuits registration.');
            }

            public function updateSealedDelivery(int $verificationTokenId, SealedSecret $sealed, \DateTimeImmutable $now): void
            {
                throw new \LogicException('Must not be called.');
            }

            public function conditionalConsumeById(int $verificationTokenId, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }

            public function markDelivered(int $verificationTokenId, ?string $providerMessageId, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }

            public function markTerminal(int $verificationTokenId, string $redactedError, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }
        };

        $challengeRepository = new class () implements VerificationChallengeRepository {
            public function findById(int $verificationChallengeId): ?VerificationChallengeRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function findByIdForUpdate(int $verificationChallengeId): ?VerificationChallengeRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function findCurrentByUserChannelForUpdate(int $userId, string $channel): ?VerificationChallengeRecord
            {
                throw new \LogicException('Must not be called.');
            }

            public function clearCurrentMarker(int $verificationChallengeId): void
            {
                throw new \LogicException('Must not be called.');
            }

            public function insertPendingCurrent(
                int $userId,
                string $channel,
                string $destinationHmac,
                string $otpBindingNonce,
                string $otpHmac,
                \DateTimeImmutable $expiresAt,
                int $maxAttempts,
                \DateTimeImmutable $now,
            ): int {
                throw new \LogicException('Must not be called: duplicate conflict short-circuits registration.');
            }

            public function updateSealedDelivery(int $verificationChallengeId, SealedSecret $sealed, \DateTimeImmutable $now): void
            {
                throw new \LogicException('Must not be called.');
            }

            public function incrementAttempt(int $verificationChallengeId): int
            {
                throw new \LogicException('Must not be called.');
            }

            public function conditionalConsumeById(int $verificationChallengeId, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }

            public function markDelivered(int $verificationChallengeId, ?string $providerMessageId, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }

            public function markTerminal(int $verificationChallengeId, string $redactedError, \DateTimeImmutable $now): bool
            {
                throw new \LogicException('Must not be called.');
            }
        };

        $outbox = new class () implements OutboxWriter {
            public function enqueue(
                string $eventType,
                string $aggregateType,
                string $aggregateId,
                array $payload,
                string $idempotencyKey,
                ?string $correlationId = null,
            ): void {
                throw new \LogicException('Must not be called: duplicate conflict short-circuits registration.');
            }
        };

        $sealedBox = new SealedSecretBox(new NotificationKeyMaterial(base64_encode(str_repeat("\1", 32)), 1));

        $tokenIssuer = new VerificationTokenIssuer(
            $transactions,
            $tokenRepository,
            new TokenHmac('registration-order-token-pepper'),
            $sealedBox,
            $outbox,
        );
        $challengeIssuer = new VerificationChallengeIssuer(
            $transactions,
            $challengeRepository,
            new OtpHmac('registration-order-otp-pepper'),
            $sealedBox,
            $outbox,
        );

        $service = new RegistrationService(
            $transactions,
            $users,
            $learnerProfiles,
            new InitialApplicantRoleBinder(),
            $tokenIssuer,
            $challengeIssuer,
            $audit,
            NotificationCapability::fromEnvFlags(true, true),
            $rateLimiter,
            new LegalAcceptancePolicy('2026-07-22', '2026-07-22'),
            new PasswordHasher(),
        );

        $result = $service->register(
            $email,
            $mobile,
            $password,
            true,
            true,
            '203.0.113.7',
        );

        self::assertFalse($result->created);
        self::assertNull($result->userId);
        self::assertSame(['hash', 'insert_attempted'], $log->events);
        self::assertIsString($log->capturedHash);
        self::assertTrue(str_starts_with($log->capturedHash, '$argon2id$'));
        self::assertTrue(password_verify($password, $log->capturedHash));
        self::assertNotSame($password, $log->capturedHash);
    }
}
