<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Exception\ValidationException;

final class LearnerNoteBodyPolicy
{
    public const MAX_LENGTH = 5000;

    public static function normalize(string $body): string
    {
        $normalized = trim(str_replace("\0", '', $body));
        if ($normalized === '') {
            throw new ValidationException('Enter a note before saving.');
        }
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new ValidationException('Notes cannot exceed ' . self::MAX_LENGTH . ' characters.');
        }

        return $normalized;
    }
}
