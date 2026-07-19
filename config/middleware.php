<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Middleware pipeline documentation (Architecture §5.2)
|--------------------------------------------------------------------------
|
| Implemented in Phase 0 (see config/container.php Kernel wiring):
|   1. TrustedProxyMiddleware
|   2. RequestIdMiddleware
|   3. ExceptionHandlerMiddleware
|   4. SecurityHeadersMiddleware
|   5. Router / controller dispatch (RouteRequestHandler)
|
| Reserved for later work packages — do not add fake/pass-through middleware:
|   - Session loading
|   - Authentication
|   - CSRF validation
|   - Rate limiting
|   - Permission enforcement
|
| Shared session/rate-limit store implementation is deferred (Decision D9).
*/

return [
    'phase0' => [
        'Academy\\Http\\Middleware\\TrustedProxyMiddleware',
        'Academy\\Http\\Middleware\\RequestIdMiddleware',
        'Academy\\Http\\Middleware\\ExceptionHandlerMiddleware',
        'Academy\\Http\\Middleware\\SecurityHeadersMiddleware',
    ],
    'deferred' => [
        'Session',
        'Authentication',
        'Csrf',
        'RateLimiting',
        'Permission',
    ],
];
