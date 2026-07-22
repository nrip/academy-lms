<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use InvalidArgumentException;

final class OtpHmac
{
    public function __construct(
        private readonly string $pepper,
    ) {
        if ($pepper === '') {
            throw new InvalidArgumentException('OTP_PEPPER must not be empty.');
        }
    }

    public function hashOtp(string $bindingNonceBinary, string $otpDigits): string
    {
        return hash_hmac('sha256', $bindingNonceBinary . $otpDigits, $this->pepper);
    }

    public function hashDestination(string $normalisedE164): string
    {
        return hash_hmac('sha256', $normalisedE164, $this->pepper);
    }

    public function equals(string $leftHex, string $rightHex): bool
    {
        return hash_equals($leftHex, $rightHex);
    }
}
