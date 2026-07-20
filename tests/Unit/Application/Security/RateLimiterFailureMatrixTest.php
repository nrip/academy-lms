<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Application\Security;

use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Security\RateLimitStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RateLimiterFailureMatrixTest extends TestCase
{
    public function testFailClosedPoliciesThrow503(): void
    {
        $store = new class () implements RateLimitStore {
            public function incrementAndGetCount(string $bucketKey, string $policyKey, \DateTimeImmutable $windowStartsAt, \DateTimeImmutable $windowEndsAt): array
            {
                throw new \RuntimeException('db down');
            }

            public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
            {
                return 0;
            }
        };

        $limiter = new RateLimiter(
            $store,
            new RateLimitKeyFactory('pepper'),
            new class () extends AbstractLogger {
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                }
            },
            [
                'auth.login_failed' => ['limit' => 5, 'window_seconds' => 900, 'failure' => RateLimiter::FAILURE_FAIL_CLOSED],
            ],
        );

        $this->expectException(ServiceUnavailableException::class);
        $limiter->hit('auth.login_failed', [['type' => 'ip', 'value' => '1.2.3.4']]);
    }

    public function testFailOpenPoliciesDoNotThrow(): void
    {
        $store = new class () implements RateLimitStore {
            public function incrementAndGetCount(string $bucketKey, string $policyKey, \DateTimeImmutable $windowStartsAt, \DateTimeImmutable $windowEndsAt): array
            {
                throw new \RuntimeException('db down');
            }

            public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
            {
                return 0;
            }
        };

        $critical = false;
        $limiter = new RateLimiter(
            $store,
            new RateLimitKeyFactory('pepper'),
            new class ($critical) extends AbstractLogger {
                public function __construct(private bool &$critical)
                {
                }

                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    if ($level === 'critical') {
                        $this->critical = true;
                    }
                }
            },
            [
                'authenticated.read' => ['limit' => 120, 'window_seconds' => 60, 'failure' => RateLimiter::FAILURE_FAIL_OPEN],
            ],
        );

        $limiter->hit('authenticated.read', [['type' => 'user', 'value' => '1']]);
        self::assertTrue($critical);
    }
}
