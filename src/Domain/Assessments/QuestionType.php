<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use Academy\Domain\Exception\ValidationException;

final class QuestionType
{
    public const MCQ_SINGLE = 'mcq_single';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::MCQ_SINGLE];
    }

    public static function assertValid(string $type): string
    {
        if (!in_array($type, self::allowed(), true)) {
            throw new ValidationException('Question type must be mcq_single.');
        }

        return $type;
    }
}
