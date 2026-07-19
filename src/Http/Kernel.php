<?php

declare(strict_types=1);

namespace Academy\Http;

use Academy\Http\Middleware\MiddlewareDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Kernel
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $handler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $dispatcher = new MiddlewareDispatcher($this->middleware, $this->handler);

        return $dispatcher->handle($request);
    }
}
