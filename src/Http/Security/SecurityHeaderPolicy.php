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
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                $this->contentSecurityPolicy(),
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

    private function contentSecurityPolicy(): string
    {
        $imgSrc = ["'self'", 'data:'];
        $logoHost = $this->httpsLogoHost();
        if ($logoHost !== null) {
            $imgSrc[] = 'https://' . $logoHost;
        }

            return "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; "
            . 'img-src ' . implode(' ', $imgSrc) . '; '
            . "font-src 'self' data:; "
            . 'media-src ' . implode(' ', $this->mediaSrc()) . '; '
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
     * @return list<string>
     */
    private function mediaSrc(): array
    {
        $sources = ["'self'"];
        if ($this->mediaSrcHost !== null && $this->mediaSrcHost !== '') {
            $sources[] = 'https://' . $this->mediaSrcHost;
        }

        return $sources;
    }
}
