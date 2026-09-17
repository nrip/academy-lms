<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

final class PasswordPolicy
{
    private const MIN_LENGTH = 12;
    private const MAX_LENGTH = 128;

    public static function minLength(): int
    {
        return self::MIN_LENGTH;
    }

    public static function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    /**
     * Short guidance shown next to password fields (matches assertAcceptable rules).
     */
    public static function guidanceText(): string
    {
        return sprintf(
            'Use at least %d characters. Do not reuse your email address or mobile number as the password.',
            self::MIN_LENGTH,
        );
    }

    public static function assertAcceptable(
        string $password,
        string $normalizedEmail,
        string $normalizedE164,
    ): void {
        $errors = [];

        $length = strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf(
                'Choose a password with at least %d characters so your account stays protected.',
                self::MIN_LENGTH,
            );
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Please shorten your password to %d characters or fewer.', self::MAX_LENGTH);
        }

        // Case-sensitive equality against already-normalized identity forms.
        if ($password === $normalizedEmail) {
            $errors[] = 'Please choose a password that is different from your email address.';
        }
        if ($password === $normalizedE164) {
            $errors[] = 'Please choose a password that is different from your mobile number.';
        }

        if ($errors !== []) {
            throw new ValidationException('Please check the details below and try again.', [
                'password' => $errors,
            ]);
        }
    }
}
