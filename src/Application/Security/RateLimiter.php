<?php

declare(strict_types=1);

namespace Academy\Application\Security;

use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Security\RateLimitStore;
use Psr\Log\LoggerInterface;
use Throwable;

final class RateLimiter
{
    public const FAILURE_FAIL_CLOSED = 'fail_closed';
    public const FAILURE_FAIL_OPEN = 'fail_open';

    /**
     * @param array<string, array{limit: int, window_seconds: int, failure: string}> $policies
     */
    public function __construct(
        private readonly RateLimitStore $store,
        private readonly RateLimitKeyFactory $keys,
        private readonly LoggerInterface $logger,
        private readonly array $policies,
    ) {
    }

    /**
     * @param list<array{type: string, value: string}> $dimensions
     */
    public function hit(string $policyKey, array $dimensions): void
    {
        $policy = $this->policies[$policyKey] ?? null;
        if ($policy === null) {
            throw new \InvalidArgumentException(sprintf('Unknown rate-limit policy "%s".', $policyKey));
        }

        foreach ($dimensions as $dimension) {
            $this->hitOne($policyKey, $policy, $dimension['type'], $dimension['value']);
        }
    }

    /**
     * @param array{limit: int, window_seconds: int, failure: string} $policy
     */
    private function hitOne(string $policyKey, array $policy, string $dimensionType, string $dimensionValue): void
    {
        $normalized = match ($dimensionType) {
            'email' => $this->keys->normalizeEmail($dimensionValue),
            'mobile' => $this->keys->normalizeMobile($dimensionValue),
            'ip' => $this->keys->normalizeIp($dimensionValue),
            default => trim($dimensionValue),
        };

        $bucketKey = $this->keys->bucketKey($policyKey, $dimensionType, $normalized);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $windowEnds = $now->modify('+' . $policy['window_seconds'] . ' seconds');

        try {
            $result = $this->store->incrementAndGetCount(
                $bucketKey,
                $policyKey,
                $now,
                $windowEnds,
            );
        } catch (Throwable $exception) {
            $this->handleStoreFailure($policy, $exception);

            return;
        }

        if ($result['hit_count'] > $policy['limit']) {
            $retryAfter = max(1, $result['window_ends_at']->getTimestamp() - $now->getTimestamp());
            throw new RateLimitExceededException($retryAfter);
        }
    }

    /**
     * @param array{limit: int, window_seconds: int, failure: string} $policy
     */
    private function handleStoreFailure(array $policy, Throwable $exception): void
    {
        if ($policy['failure'] === self::FAILURE_FAIL_OPEN) {
            $this->logger->critical('Rate-limit store unavailable; failing open for policy class.', [
                'exception' => $exception::class,
            ]);

            return;
        }

        $this->logger->critical('Rate-limit store unavailable; failing closed.', [
            'exception' => $exception::class,
        ]);
        throw new ServiceUnavailableException('Rate-limit store unavailable.');
    }
}
