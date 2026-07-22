<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use InvalidArgumentException;

final class TokenHmac
{
    public function __construct(
        private readonly string $pepper,
    ) {
        if ($pepper === '') {
            throw new InvalidArgumentException('TOKEN_PEPPER must not be empty.');
        }
    }

    public function hash(string $rawToken): string
    {
        return hash_hmac('sha256', $rawToken, $this->pepper);
    }

    public function equals(string $leftHex, string $rightHex): bool
    {
        return hash_equals($leftHex, $rightHex);
    }

    /**
     * Safe missing-token rate-limit dimension (never the raw token).
     */
    public function missingTokenDimension(string $rawToken): string
    {
        return hash_hmac('sha256', 'missing|' . $rawToken, $this->pepper);
    }
}
