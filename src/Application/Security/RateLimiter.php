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
        [$windowStarts, $windowEnds] = $this->fixedWindowBounds($now, $policy['window_seconds']);

        try {
            $hitCount = $this->store->incrementAndGetCount(
                $bucketKey,
                $policyKey,
                $windowStarts,
                $windowEnds,
            );
        } catch (Throwable $exception) {
            $this->handleStoreFailure($policy, $exception);

            return;
        }

        if ($hitCount > $policy['limit']) {
            $retryAfter = max(1, $windowEnds->getTimestamp() - $now->getTimestamp());
            throw new RateLimitExceededException($retryAfter);
        }
    }

    /**
     * Fixed window: boundaries are deterministic from request time + window size.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function fixedWindowBounds(\DateTimeImmutable $now, int $windowSeconds): array
    {
        $epoch = $now->getTimestamp();
        $startTs = intdiv($epoch, $windowSeconds) * $windowSeconds;
        $windowStarts = (new \DateTimeImmutable('@' . $startTs))->setTimezone(new \DateTimeZone('UTC'));
        $windowEnds = $windowStarts->modify('+' . $windowSeconds . ' seconds');

        return [$windowStarts, $windowEnds];
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
