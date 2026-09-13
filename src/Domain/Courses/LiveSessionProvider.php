<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

/**
 * Join-link providers. Derived from the URL host. No vendor API.
 */
final class LiveSessionProvider
{
    public const GOOGLE_MEET = 'google_meet';
    public const ZOOM = 'zoom';
    public const TEAMS = 'teams';
    public const CUSTOM = 'custom';

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::GOOGLE_MEET, self::ZOOM, self::TEAMS, self::CUSTOM];
    }

    public static function fromJoinUrl(string $url): string
    {
        $https = self::assertHttps($url, 'Live session join URL must be an HTTPS link.');
        $host = strtolower((string) parse_url($https, PHP_URL_HOST));

        if ($host === 'meet.google.com') {
            return self::GOOGLE_MEET;
        }
        if ($host === 'zoom.us' || str_ends_with($host, '.zoom.us')) {
            return self::ZOOM;
        }
        if ($host === 'teams.microsoft.com' || $host === 'teams.live.com') {
            return self::TEAMS;
        }

        return self::CUSTOM;
    }

    public static function assertHttps(string $url, string $message): string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || !str_starts_with($trimmed, 'https://') || preg_match('/\s/', $trimmed) === 1) {
            throw new ValidationException($message);
        }
        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new ValidationException($message);
        }

        return $trimmed;
    }
}
