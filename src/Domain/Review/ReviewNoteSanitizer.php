<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

final class ReviewNoteSanitizer
{
    public const LEARNER_MAX_LENGTH = 500;
    public const INTERNAL_MAX_LENGTH = 1000;

    public static function sanitizeLearnerVisible(?string $message): ?string
    {
        return self::sanitize($message, self::LEARNER_MAX_LENGTH);
    }

    public static function sanitizeInternal(?string $note): ?string
    {
        return self::sanitize($note, self::INTERNAL_MAX_LENGTH);
    }

    private static function sanitize(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $stripped = strip_tags($value);
        $trimmed = trim($stripped);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $maxLength) {
            return mb_substr($trimmed, 0, $maxLength);
        }

        return $trimmed;
    }
}
