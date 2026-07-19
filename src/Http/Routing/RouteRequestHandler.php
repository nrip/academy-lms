<?php

declare(strict_types=1);

namespace Academy\Http\Routing;

use League\Route\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Router $router,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->router->dispatch($request);
    }
}
