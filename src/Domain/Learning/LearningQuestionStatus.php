<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

final class LearningQuestionStatus
{
    public const OPEN = 'open';
    public const ANSWERED = 'answered';
    public const CLOSED = 'closed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::OPEN, self::ANSWERED, self::CLOSED];
    }
}
