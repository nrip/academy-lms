<?php

declare(strict_types=1);

namespace Academy\Http\Routing;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Http\Middleware\RequirePermissionMiddleware;
use League\Route\Route;

/**
 * Helper for attaching constructor-bound permission middleware to matched routes.
 *
 * Permission middleware must stay route-level (after FastRoute match). See
 * RequirePermissionMiddleware docblock — do not register it on the Kernel pipeline.
 */
final class RouteAccess
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function requirePermission(Route $route, string $permissionKey): Route
    {
        $route->middleware(new RequirePermissionMiddleware($this->authorization, $permissionKey));

        return $route;
    }
}
