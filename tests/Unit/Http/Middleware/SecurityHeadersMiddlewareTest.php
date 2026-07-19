<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Http\Middleware;

use Academy\Http\Middleware\SecurityHeadersMiddleware;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsBaselineSecurityHeaders(): void
    {
        $middleware = new SecurityHeadersMiddleware(false);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $response = $middleware->process(new ServerRequest(), $handler);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }
}
