<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

final class VideoProvider
{
    public const YOUTUBE = 'youtube';
    public const YOUTUBE_NOCOOKIE = 'youtube_nocookie';
    public const VIMEO = 'vimeo';
    public const EXTERNAL = 'external';

    /** @return list<string> */
    public static function embeddable(): array
    {
        return [self::YOUTUBE, self::YOUTUBE_NOCOOKIE, self::VIMEO];
    }

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::YOUTUBE, self::YOUTUBE_NOCOOKIE, self::VIMEO, self::EXTERNAL];
    }

    public static function assertValid(string $provider): string
    {
        if (!in_array($provider, self::allowed(), true)) {
            throw new ValidationException('Video provider is not supported.');
        }

        return $provider;
    }
}
