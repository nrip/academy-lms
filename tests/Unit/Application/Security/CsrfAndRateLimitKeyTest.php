<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Security;

use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Application\Security\SessionService;
use PHPUnit\Framework\TestCase;

final class CsrfAndRateLimitKeyTest extends TestCase
{
    public function testCsrfHashOnlyValidationIsTimingSafe(): void
    {
        $csrf = new CsrfTokenManager();
        $raw = $csrf->generateRawToken();
        $hash = $csrf->hash($raw);
        self::assertTrue($csrf->validate($raw, $hash));
        self::assertFalse($csrf->validate('wrong', $hash));
        self::assertNotSame($raw, $hash);
    }

    public function testRateLimitKeyNeverContainsRawIdentifiers(): void
    {
        $factory = new RateLimitKeyFactory('test-pepper-value');
        $email = 'Learner@Example.com';
        $key = $factory->bucketKey('auth.otp_send.15m', 'email', $factory->normalizeEmail($email));
        self::assertSame(64, strlen($key));
        self::assertStringNotContainsString('learner', $key);
        self::assertStringNotContainsString('example.com', $key);
        self::assertStringNotContainsString($email, $key);
    }

    public function testTimeoutDefaultsMatchApprovedPlan(): void
    {
        $service = new SessionService(
            new class () implements \Academy\Domain\Security\SessionRepository {
                public function findByTokenHash(string $tokenHash): ?\Academy\Domain\Security\SessionRecord
                {
                    return null;
                }

                public function create(string $tokenHash, ?string $csrfTokenHash, array $payload, \DateTimeImmutable $createdAt, \DateTimeImmutable $absoluteExpiresAt, \DateTimeImmutable $idleExpiresAt, ?string $ipAddress, ?string $userAgentHash): \Academy\Domain\Security\SessionRecord
                {
                    throw new \RuntimeException('unused');
                }

                public function regenerate(int $sessionId, string $newTokenHash, ?string $newCsrfTokenHash, \DateTimeImmutable $now, \DateTimeImmutable $absoluteExpiresAt, \DateTimeImmutable $idleExpiresAt): void
                {
                }

                public function updateCsrfHash(int $sessionId, string $csrfTokenHash): void
                {
                }

                public function touch(int $sessionId, \DateTimeImmutable $lastActivityAt, \DateTimeImmutable $idleExpiresAt): void
                {
                }

                public function revoke(int $sessionId, \DateTimeImmutable $revokedAt): void
                {
                }

                public function bindUser(int $sessionId, int $userId, int $authVersion, array $payloadMerge = []): void
                {
                }

                public function mergeAnonymousPayload(int $sessionId, array $payloadMerge): void
                {
                }

                public function revokeAllForUser(int $userId, \DateTimeImmutable $revokedAt): int
                {
                    return 0;
                }

                public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
                {
                    return 0;
                }
            },
            new CsrfTokenManager(),
            new \Psr\Log\NullLogger(),
            ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
            ['idle_seconds' => 900, 'absolute_seconds' => 28800],
            300,
        );

        self::assertSame(1800, $service->timeoutsForClass(SessionService::TIMEOUT_DEFAULT)['idle_seconds']);
        self::assertSame(43200, $service->timeoutsForClass(SessionService::TIMEOUT_DEFAULT)['absolute_seconds']);
        self::assertSame(900, $service->timeoutsForClass(SessionService::TIMEOUT_PRIVILEGED)['idle_seconds']);
        self::assertSame(28800, $service->timeoutsForClass(SessionService::TIMEOUT_PRIVILEGED)['absolute_seconds']);
        self::assertSame(300, $service->activityWriteThrottleSeconds());
    }
}
