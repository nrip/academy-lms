<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Http\Security\SecurityHeaderPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public function __construct(
        private readonly SecurityHeaderPolicy $policy,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'SecurityHeaders');

        return $this->policy->apply($request, $handler->handle($request));
    }
}
