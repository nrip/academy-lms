<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

/**
 * Parses learner/admin video URLs into a safe VideoSource.
 * Only approved providers may produce embed URLs — never accept raw iframe HTML.
 */
final class SafeVideoEmbedBuilder
{
    public function build(string $rawUrl, string $deliveryMode): VideoSource
    {
        $mode = VideoDeliveryMode::assertValid(trim($deliveryMode));
        if ($mode === VideoDeliveryMode::UPLOAD) {
            throw new ValidationException('Uploaded video uses a private media file, not a video URL.');
        }
        $url = trim($rawUrl);
        if ($url === '') {
            throw new ValidationException('Video URL is required.');
        }
        if (mb_strlen($url) > 2048) {
            throw new ValidationException('Video URL must be 2048 characters or fewer.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            throw new ValidationException('Video URL contains invalid characters.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ValidationException('Enter a valid video URL (including https://).');
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new ValidationException('Video URLs must use HTTPS.');
        }

        $host = strtolower((string) $parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        if ($mode === VideoDeliveryMode::EMBEDDED) {
            return $this->buildEmbedded($url, $host, $parts);
        }

        return new VideoSource(
            deliveryMode: VideoDeliveryMode::EXTERNAL_LINK,
            provider: VideoProvider::EXTERNAL,
            sourceUrl: $url,
            embedUrl: null,
        );
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function buildEmbedded(string $url, string $host, array $parts): VideoSource
    {
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'], true)) {
            $id = $this->youtubeId($host, $parts);
            if ($id === null) {
                throw new ValidationException(
                    'Could not recognise a YouTube video ID. Use a standard youtube.com or youtu.be link.',
                );
            }

            return new VideoSource(
                deliveryMode: VideoDeliveryMode::EMBEDDED,
                provider: VideoProvider::YOUTUBE,
                sourceUrl: $url,
                embedUrl: 'https://www.youtube.com/embed/' . $id,
            );
        }

        if ($host === 'youtube-nocookie.com') {
            $id = $this->youtubeId($host, $parts);
            if ($id === null) {
                throw new ValidationException(
                    'Could not recognise a YouTube video ID. Use a youtube-nocookie.com embed or watch link.',
                );
            }

            return new VideoSource(
                deliveryMode: VideoDeliveryMode::EMBEDDED,
                provider: VideoProvider::YOUTUBE_NOCOOKIE,
                sourceUrl: $url,
                embedUrl: 'https://www.youtube-nocookie.com/embed/' . $id,
            );
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            $id = $this->vimeoId($host, $parts);
            if ($id === null) {
                throw new ValidationException(
                    'Could not recognise a Vimeo video ID. Use a standard vimeo.com link.',
                );
            }

            return new VideoSource(
                deliveryMode: VideoDeliveryMode::EMBEDDED,
                provider: VideoProvider::VIMEO,
                sourceUrl: $url,
                embedUrl: 'https://player.vimeo.com/video/' . $id,
            );
        }

        throw new ValidationException(
            'Embedded Player only supports YouTube, YouTube no-cookie, or Vimeo. Use External Link for other HTTPS URLs.',
        );
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function youtubeId(string $host, array $parts): ?string
    {
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
            $id = explode('/', $id)[0] ?? '';

            return $this->assertVideoId($id) ? $id : null;
        }

        if (isset($query['v']) && is_string($query['v']) && $this->assertVideoId($query['v'])) {
            return $query['v'];
        }

        if (preg_match('#^/(embed|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $matches) === 1) {
            return $matches[2];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function vimeoId(string $host, array $parts): ?string
    {
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($host === 'player.vimeo.com') {
            if (preg_match('#^/video/(\d+)#', $path, $matches) === 1) {
                return $matches[1];
            }

            return null;
        }

        if (preg_match('#^/(\d+)(?:/|$)#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function assertVideoId(string $id): bool
    {
        return $id !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) === 1;
    }
}
