<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

final class VideoDeliveryMode
{
    public const EMBEDDED = 'embedded';
    public const EXTERNAL_LINK = 'external_link';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::EMBEDDED, self::EXTERNAL_LINK];
    }

    public static function assertValid(string $mode): string
    {
        if (!in_array($mode, self::allowed(), true)) {
            throw new ValidationException('Choose Embedded Player or External Link for video delivery.');
        }

        return $mode;
    }
}
