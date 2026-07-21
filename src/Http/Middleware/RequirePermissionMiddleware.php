<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Security\AuthContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Route-level permission gate (Design A).
 *
 * Must remain attached via League\Route route/group middleware AFTER FastRoute match.
 * Kernel AuthenticationMiddleware runs before the router and has no matched-route metadata;
 * do not move permission checks into the global Kernel pipeline or match on raw URI strings.
 * Permission keys are constructor-bound at registration time through RouteAccess.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly string $permissionKey,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'Permission');

        /** @var AuthContext|null $context */
        $context = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$context instanceof AuthContext || !$context->authenticated) {
            throw new AuthenticationException('Authentication required.');
        }

        try {
            $this->authorization->require($context, $this->permissionKey);
        } catch (AuthenticationException | AuthorizationException | ServiceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ServiceUnavailableException('Permission store unavailable.');
        }

        return $handler->handle($request);
    }
}
