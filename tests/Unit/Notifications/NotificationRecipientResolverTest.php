<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Notifications;

use Academy\Application\Notifications\NotificationRecipientResolver;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Notifications\NotificationFailureCategory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class NotificationRecipientResolverTest extends TestCase
{
    public function testMaskEmailViaReflection(): void
    {
        $resolver = new NotificationRecipientResolver($this->usersStub([]), 'pepper');
        $method = new ReflectionMethod(NotificationRecipientResolver::class, 'maskEmail');
        $method->setAccessible(true);

        self::assertSame('a**@example.test', $method->invoke($resolver, 'ada@example.test'));
        self::assertSame('*@example.test', $method->invoke($resolver, 'a@example.test'));
    }

    public function testSuspendedMapsToAccountSuspendedCategory(): void
    {
        $resolver = new NotificationRecipientResolver($this->usersStub([
            'user_id' => 7,
            'account_status' => AccountStatus::SUSPENDED,
            'email' => 'susp@example.test',
            'mobile_e164' => '+919999999999',
            'email_verified_at' => '2026-01-01 00:00:00.000000',
            'mobile_verified_at' => '2026-01-01 00:00:00.000000',
        ]), 'pepper');

        try {
            $resolver->resolveVerifiedEmail(7);
            self::fail('Expected DomainRuleException');
        } catch (DomainRuleException $exception) {
            self::assertSame(NotificationFailureCategory::ACCOUNT_SUSPENDED, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function usersStub(array $user): UserWriteRepository
    {
        return new class ($user) implements UserWriteRepository {
            /** @param array<string, mixed> $user */
            public function __construct(private readonly array $user)
            {
            }

            public function insertPendingUser(
                string $normalizedEmail,
                string $normalizedMobileE164,
                string $passwordHash,
                string $termsVersion,
                string $privacyVersion,
                DateTimeImmutable $now,
                string $timezone = 'Asia/Kolkata',
            ): int {
                throw new \RuntimeException('not used');
            }

            public function findById(int $userId): ?array
            {
                return $this->user === [] ? null : $this->user;
            }

            public function findByIdForUpdate(int $userId): ?array
            {
                return null;
            }

            public function findByEmail(string $normalizedEmail): ?array
            {
                return null;
            }

            public function findByMobileE164(string $normalizedMobileE164): ?array
            {
                return null;
            }

            public function findCredentialsByEmailForUpdate(string $normalizedEmail): ?array
            {
                return null;
            }

            public function applyFailedLogin(int $userId, array $state): void
            {
            }

            public function applySuccessfulLogin(int $userId, array $state, ?string $rehashedPassword): void
            {
            }

            public function applyPasswordReset(int $userId, string $passwordHash, DateTimeImmutable $now): array
            {
                return ['auth_version_before' => 1, 'auth_version_after' => 2];
            }

            public function applyEmailVerification(int $userId, DateTimeImmutable $now): array
            {
                return ['email_was_null' => false, 'activated' => false, 'account_status' => AccountStatus::ACTIVE];
            }

            public function applyMobileVerification(int $userId, DateTimeImmutable $now): bool
            {
                return false;
            }
        };
    }
}
