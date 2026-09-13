<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

final class ContentItemType
{
    public const TEXT_LESSON = 'text_lesson';
    public const RICH_TEXT = 'rich_text';
    public const PDF = 'pdf';
    public const MCQ_ASSESSMENT = 'mcq_assessment';
    public const VIDEO = 'video';
    public const PODCAST = 'podcast';
    public const AUDIO = 'audio';
    public const LIVE_SESSION = 'live_session';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [
            self::TEXT_LESSON,
            self::RICH_TEXT,
            self::PDF,
            self::MCQ_ASSESSMENT,
            self::VIDEO,
            self::PODCAST,
            self::AUDIO,
            self::LIVE_SESSION,
        ];
    }

    /** Types Course Admins may create in the curriculum builder. */
    /** @return list<string> */
    public static function creatableInBuilder(): array
    {
        return self::allowed();
    }

    /**
     * Lessons the learner confirms with Mark complete / I attended.
     * Quiz completion stays assessment_passed and is not in this list.
     *
     * @return list<string>
     */
    public static function learnerMarkCompleteTypes(): array
    {
        return [
            self::TEXT_LESSON,
            self::RICH_TEXT,
            self::PDF,
            self::VIDEO,
            self::PODCAST,
            self::AUDIO,
            self::LIVE_SESSION,
        ];
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
            throw new ValidationException('Content type cannot be created in the curriculum builder.');
        }

        return $type;
    }
}
