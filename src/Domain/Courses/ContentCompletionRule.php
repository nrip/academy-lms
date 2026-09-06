<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

final class ContentCompletionRule
{
    public const MARK_COMPLETE = 'mark_complete';
    public const ASSESSMENT_PASSED = 'assessment_passed';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::MARK_COMPLETE, self::ASSESSMENT_PASSED];
    }

    public static function assertValid(string $rule): string
    {
        if (!in_array($rule, self::allowed(), true)) {
            throw new ValidationException('Completion rule must be mark_complete or assessment_passed.');
        }

        return $rule;
    }

    public static function defaultForType(string $contentType): string
    {
        return $contentType === ContentItemType::MCQ_ASSESSMENT
            ? self::ASSESSMENT_PASSED
            : self::MARK_COMPLETE;
    }
}
