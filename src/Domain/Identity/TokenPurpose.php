<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use InvalidArgumentException;

final class TokenPurpose
{
    public const EMAIL_VERIFY = 'email_verify';
    public const PASSWORD_RESET = 'password_reset';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EMAIL_VERIFY, self::PASSWORD_RESET];
    }

    public static function assertValid(string $purpose): void
    {
        if (!in_array($purpose, self::all(), true)) {
            throw new InvalidArgumentException('Invalid token purpose.');
        }
    }
}
