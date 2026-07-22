<?php

declare(strict_types=1);

namespace Academy\Application\Security;

use Academy\Domain\Identity\MobileE164Normalizer;

/**
 * Builds rate-limit bucket keys via server-keyed HMAC.
 * Never includes window_id (single-row reset design — Q4).
 * Never embeds raw email/mobile/PII in bucket_key.
 */
final class RateLimitKeyFactory
{
    public function __construct(
        private readonly string $pepper,
    ) {
        if ($pepper === '') {
            throw new \InvalidArgumentException('RATE_LIMIT_PEPPER must not be empty.');
        }
    }

    public function bucketKey(string $policyKey, string $dimensionType, string $normalizedDimension): string
    {
        $message = $policyKey . "\0" . $dimensionType . "\0" . $normalizedDimension;

        return hash_hmac('sha256', $message, $this->pepper);
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function normalizeIp(string $ip): string
    {
        return trim($ip);
    }

    /**
     * Canonical E.164 for mobile rate-limit dimensions (HMAC input only; never stored plaintext in bucket_key).
     */
    public function normalizeMobile(string $mobile): string
    {
        return MobileE164Normalizer::normalize($mobile);
    }

    /**
     * Preferred mobile dimension: raw input → E.164 → RateLimiter HMAC bucket.
     *
     * @return array{type: string, value: string}
     */
    public function mobileE164Dimension(string $rawMobile): array
    {
        return [
            'type' => 'mobile_e164',
            'value' => MobileE164Normalizer::normalize($rawMobile),
        ];
    }
}
