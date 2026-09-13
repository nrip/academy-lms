<?php

declare(strict_types=1);

namespace Academy\Http\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Single source of truth for baseline security headers on success and error responses.
 */
final class SecurityHeaderPolicy
{
    /**
     * Consumed and removed. Comma-separated HTTPS hosts for one response's media-src.
     * Used so a validated direct podcast file can play in-page without a wildcard.
     */
    public const EXTRA_MEDIA_SRC_HEADER = 'X-Academy-Media-Src';

    public function __construct(
        private readonly bool $enableHsts,
        private readonly ?string $logoUrl = null,
        private readonly ?string $mediaSrcHost = null,
    ) {
    }

    public function apply(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Token/confirmation surfaces may set stricter headers first — do not overwrite.
        if (!$response->hasHeader('X-Content-Type-Options')) {
            $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        }
        if (!$response->hasHeader('X-Frame-Options')) {
            $response = $response->withHeader('X-Frame-Options', 'DENY');
        }
        if (!$response->hasHeader('Referrer-Policy')) {
            $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if (!$response->hasHeader('Permissions-Policy')) {
            $response = $response->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        }
        $extraMediaHosts = $this->extraMediaHosts($response);
        if ($response->hasHeader(self::EXTRA_MEDIA_SRC_HEADER)) {
            $response = $response->withoutHeader(self::EXTRA_MEDIA_SRC_HEADER);
        }
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                $this->contentSecurityPolicy($extraMediaHosts),
            );
        }
        if (!$response->hasHeader('Cross-Origin-Opener-Policy')) {
            $response = $response->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        }
        if (!$response->hasHeader('Cross-Origin-Resource-Policy')) {
            $response = $response->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        }

        if ($this->enableHsts && $request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * @param list<string> $extraMediaHosts
     */
    private function contentSecurityPolicy(array $extraMediaHosts = []): string
    {
        $imgSrc = ["'self'", 'data:'];
        $logoHost = $this->httpsLogoHost();
        if ($logoHost !== null) {
            $imgSrc[] = 'https://' . $logoHost;
        }

        return "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; "
        . 'img-src ' . implode(' ', $imgSrc) . '; '
        . "font-src 'self' data:; "
        . 'media-src ' . implode(' ', $this->mediaSrc($extraMediaHosts)) . '; '
        . "worker-src 'self'; "
        . "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
    }

    private function httpsLogoHost(): ?string
    {
        if ($this->logoUrl === null || !str_starts_with($this->logoUrl, 'https://')) {
            return null;
        }
        $host = parse_url($this->logoUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /**
     * @param list<string> $extraHosts
     * @return list<string>
     */
    private function mediaSrc(array $extraHosts = []): array
    {
        $sources = ["'self'"];
        if ($this->mediaSrcHost !== null && $this->mediaSrcHost !== '') {
            $sources[] = 'https://' . $this->mediaSrcHost;
        }
        foreach ($extraHosts as $host) {
            $source = 'https://' . $host;
            if (!in_array($source, $sources, true)) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function extraMediaHosts(ResponseInterface $response): array
    {
        if (!$response->hasHeader(self::EXTRA_MEDIA_SRC_HEADER)) {
            return [];
        }
        $hosts = [];
        foreach (explode(',', $response->getHeaderLine(self::EXTRA_MEDIA_SRC_HEADER)) as $raw) {
            $host = strtolower(trim($raw));
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
                continue;
            }
            $hosts[] = $host;
        }

        return $hosts;
    }
}
