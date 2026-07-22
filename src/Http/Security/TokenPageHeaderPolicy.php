<?php

declare(strict_types=1);

namespace Academy\Http\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * Stricter headers for verification/reset token pages (scanner-safe surfaces).
 */
final class TokenPageHeaderPolicy
{
    public function apply(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Cache-Control', 'no-store');
    }
}
