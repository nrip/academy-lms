<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

/**
 * Incoming raw verification/reset token format: exactly 64 hexadecimal characters.
 */
final class RawVerificationToken
{
    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function parse(string $raw): self
    {
        if ($raw === '' || strlen($raw) !== 64 || !ctype_xdigit($raw)) {
            throw new ValidationException('Invalid verification token.');
        }

        return new self(strtolower($raw));
    }

    public function value(): string
    {
        return $this->value;
    }
}
