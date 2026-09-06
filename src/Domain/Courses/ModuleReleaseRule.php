<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

final class ModuleReleaseRule
{
    public const IMMEDIATE = 'immediate';
    public const SEQUENTIAL = 'sequential';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::IMMEDIATE, self::SEQUENTIAL];
    }

    public static function assertValid(string $rule): string
    {
        if (!in_array($rule, self::allowed(), true)) {
            throw new ValidationException('Release rule must be immediate or sequential.');
        }

        return $rule;
    }
}
