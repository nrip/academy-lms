<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

final class ContentProgressCompletionStatus
{
    public const NOT_STARTED = 'not_started';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';

    /** @var list<string> */
    public const ALL = [
        self::NOT_STARTED,
        self::IN_PROGRESS,
        self::COMPLETED,
    ];

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::ALL, true)) {
            throw new \InvalidArgumentException('Invalid content progress completion status.');
        }
    }
}
