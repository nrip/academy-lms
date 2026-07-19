<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $fallback,
        private readonly int $index = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return $this->fallback->handle($request);
        }

        $middleware = $this->middleware[$this->index];
        $next = new self($this->middleware, $this->fallback, $this->index + 1);

        return $middleware->process($request, $next);
    }
}
