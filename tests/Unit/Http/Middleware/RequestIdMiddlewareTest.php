<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Http\Middleware;

use Academy\Http\Middleware\RequestIdMiddleware;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddlewareTest extends TestCase
{
    public function testGeneratesRequestIdWhenMissing(): void
    {
        $middleware = new RequestIdMiddleware();
        $handler = new class () implements RequestHandlerInterface {
            public ?string $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

                return new Response();
            }
        };

        $response = $middleware->process(new ServerRequest(), $handler);

        self::assertNotSame('', $handler->seen);
        self::assertSame($handler->seen, $response->getHeaderLine(RequestIdMiddleware::HEADER));
    }

    public function testPreservesIncomingRequestId(): void
    {
        $middleware = new RequestIdMiddleware();
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $request = (new ServerRequest())->withHeader(RequestIdMiddleware::HEADER, 'fixed-id-123');
        $response = $middleware->process($request, $handler);

        self::assertSame('fixed-id-123', $response->getHeaderLine(RequestIdMiddleware::HEADER));
    }
}
