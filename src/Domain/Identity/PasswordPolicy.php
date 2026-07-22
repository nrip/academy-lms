<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

final class PasswordPolicy
{
    private const MIN_LENGTH = 12;
    private const MAX_LENGTH = 128;

    public static function assertAcceptable(
        string $password,
        string $normalizedEmail,
        string $normalizedE164,
    ): void {
        $errors = [];

        $length = strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf('Password must be at least %d characters.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Password must be at most %d characters.', self::MAX_LENGTH);
        }

        // Case-sensitive equality against already-normalized identity forms.
        if ($password === $normalizedEmail) {
            $errors[] = 'Password must not match your email address.';
        }
        if ($password === $normalizedE164) {
            $errors[] = 'Password must not match your mobile number.';
        }

        if ($errors !== []) {
            throw new ValidationException('Please correct the highlighted fields.', [
                'password' => $errors,
            ]);
        }
    }
}
