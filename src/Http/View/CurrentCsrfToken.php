<?php

declare(strict_types=1);

namespace Academy\Http\View;

/**
 * Request-scoped raw CSRF token holder for view composition (navigation forms).
 * Set by SessionMiddleware; read by PhpRenderer.
 *
 * Controllers still pass 'csrf' explicitly for their own forms; this holder only
 * guarantees shell-level POST controls (logout) always receive the session token,
 * including on pages and error responses that render no form of their own.
 */
final class CurrentCsrfToken
{
    private string $token = '';

    public function set(string $token): void
    {
        $this->token = $token;
    }

    public function get(): string
    {
        return $this->token;
    }

    public function clear(): void
    {
        $this->token = '';
    }
}
