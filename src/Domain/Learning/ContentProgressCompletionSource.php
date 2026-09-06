<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

final class ContentProgressCompletionSource
{
    public const LEARNER = 'learner';
    public const ASSESSMENT = 'assessment';
    public const SYSTEM = 'system';

    /** @var list<string> */
    public const ALL = [
        self::LEARNER,
        self::ASSESSMENT,
        self::SYSTEM,
    ];
}
