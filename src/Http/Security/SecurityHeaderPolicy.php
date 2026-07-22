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
                "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; font-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
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
}
