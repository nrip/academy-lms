<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Middleware pipeline (Architecture §5.2 + WP-01A)
|--------------------------------------------------------------------------
|
| Observed execution order is covered by tests — do not rely only on this list.
|
| Outer → inner:
|   1. TrustedProxyMiddleware
|   2. RequestIdMiddleware
|   3. ExceptionHandlerMiddleware  (outer catcher; applies SecurityHeaderPolicy on errors)
|   4. SecurityHeadersMiddleware   (success path; shared SecurityHeaderPolicy)
|   5. SessionMiddleware
|   6. AuthenticationMiddleware
|   7. CsrfMiddleware
|   8. RateLimitMiddleware
|   9. Router / controller
|
| Still deferred (not stubbed):
|   - Permission enforcement (WP-01B+)
*/

return [
    'pipeline' => [
        'Academy\\Http\\Middleware\\TrustedProxyMiddleware',
        'Academy\\Http\\Middleware\\RequestIdMiddleware',
        'Academy\\Http\\Middleware\\ExceptionHandlerMiddleware',
        'Academy\\Http\\Middleware\\SecurityHeadersMiddleware',
        'Academy\\Http\\Middleware\\SessionMiddleware',
        'Academy\\Http\\Middleware\\AuthenticationMiddleware',
        'Academy\\Http\\Middleware\\CsrfMiddleware',
        'Academy\\Http\\Middleware\\RateLimitMiddleware',
    ],
    'deferred' => [
        'Permission',
    ],
];
