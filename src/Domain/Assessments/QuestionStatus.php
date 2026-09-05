<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use Academy\Domain\Exception\ValidationException;

final class QuestionStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::ACTIVE, self::INACTIVE];
    }

    public static function assertValid(string $status): string
    {
        if (!in_array($status, self::allowed(), true)) {
            throw new ValidationException('Question status must be active or inactive.');
        }

        return $status;
    }
}
