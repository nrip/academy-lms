<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

final class ContentItemType
{
    public const TEXT_LESSON = 'text_lesson';
    public const PDF = 'pdf';
    public const MCQ_ASSESSMENT = 'mcq_assessment';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::TEXT_LESSON, self::PDF, self::MCQ_ASSESSMENT];
    }

    /** Types Course Admins may create in WP-L3 (assessment runtime deferred). */
    /** @return list<string> */
    public static function creatableInBuilder(): array
    {
        return [self::TEXT_LESSON, self::PDF];
    }

    public static function assertValid(string $type): string
    {
        if (!in_array($type, self::allowed(), true)) {
            throw new ValidationException('Content type is not supported.');
        }

        return $type;
    }

    public static function assertCreatable(string $type): string
    {
        self::assertValid($type);
        if (!in_array($type, self::creatableInBuilder(), true)) {
            throw new ValidationException(
                'MCQ assessment content items arrive in a later work package. Create a text lesson or PDF for now.',
            );
        }

        return $type;
    }
}
