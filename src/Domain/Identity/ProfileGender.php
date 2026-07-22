<?php

declare(strict_types=1);

namespace Academy\Domain\Identity;

use Academy\Domain\Exception\ValidationException;

final class ProfileGender
{
    /** @var list<string> */
    public const ALLOWED = [
        'female',
        'male',
        'other',
        'prefer_not_to_say',
    ];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }

        if (!in_array($value, self::ALLOWED, true)) {
            throw new ValidationException('Please correct the highlighted fields.', [
                'gender' => ['Select a valid gender option.'],
            ]);
        }

        return $value;
    }
}
