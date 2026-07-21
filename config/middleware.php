<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Middleware pipeline (Architecture §5.2 + WP-01A + WP-01B-1)
|--------------------------------------------------------------------------
|
| Observed execution order is covered by tests — do not rely only on this list.
|
| Outer → inner (Kernel):
|   1. TrustedProxyMiddleware
|   2. RequestIdMiddleware
|   3. ExceptionHandlerMiddleware  (outer catcher; applies SecurityHeaderPolicy on errors)
|   4. SecurityHeadersMiddleware   (success path; shared SecurityHeaderPolicy)
|   5. SessionMiddleware
|   6. AuthenticationMiddleware    (security snapshot; no route metadata)
|   7. RateLimitMiddleware         (pre-CSRF; IP/session/path dimensions only)
|   8. CsrfMiddleware
|   9. Router / controller
|
| Permission enforcement is NOT in the Kernel pipeline.
| RequirePermissionMiddleware attaches to matched League\Route routes/groups
| after FastRoute match (constructor-bound keys via RouteAccess). Do not add a
| global Permission middleware or match permissions by raw URI string.
*/

return [
    'pipeline' => [
        'Academy\\Http\\Middleware\\TrustedProxyMiddleware',
        'Academy\\Http\\Middleware\\RequestIdMiddleware',
        'Academy\\Http\\Middleware\\ExceptionHandlerMiddleware',
        'Academy\\Http\\Middleware\\SecurityHeadersMiddleware',
        'Academy\\Http\\Middleware\\SessionMiddleware',
        'Academy\\Http\\Middleware\\AuthenticationMiddleware',
        'Academy\\Http\\Middleware\\RateLimitMiddleware',
        'Academy\\Http\\Middleware\\CsrfMiddleware',
    ],
    'deferred' => [
        // Intentionally empty for Kernel — permission is route-level only.
    ],
];
