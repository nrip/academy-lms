<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

final class EmailNormalizer
{
    private const MAX_LENGTH = 255;

    public static function normalize(string $raw): string
    {
        $normalized = strtolower(trim($raw));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Please correct the highlighted fields.', [
                'email' => ['Enter a valid email address.'],
            ]);
        }

        if (strlen($normalized) > self::MAX_LENGTH) {
            throw new ValidationException('Please correct the highlighted fields.', [
                'email' => [sprintf('Email must be at most %d characters.', self::MAX_LENGTH)],
            ]);
        }

        return $normalized;
    }
}
