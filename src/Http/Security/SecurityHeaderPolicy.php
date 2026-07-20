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
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; font-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            )
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');

        if ($this->enableHsts && $request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
