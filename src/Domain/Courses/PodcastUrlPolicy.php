<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

final class PodcastUrlPolicy
{
    public static function assertUrl(string $url): string
    {
        return LiveSessionProvider::assertHttps($url, 'Podcast URL must be an HTTPS link.');
    }

    public static function isDirectAudioFile(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        $lower = strtolower($path);

        return str_ends_with($lower, '.mp3')
            || str_ends_with($lower, '.m4a')
            || str_ends_with($lower, '.wav');
    }
}
