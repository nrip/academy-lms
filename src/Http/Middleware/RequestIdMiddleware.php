<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public const ATTRIBUTE = 'request_id';
    public const HEADER = 'X-Request-Id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine(self::HEADER);
        $requestId = $incoming !== '' ? $incoming : $this->generate();

        $request = $request
            ->withAttribute(self::ATTRIBUTE, $requestId)
            ->withHeader(self::HEADER, $requestId);

        if ($request->getHeaderLine('X-WP01A-Trace') === '1') {
            $request = $request->withAttribute('middleware.trace', true);
        }

        $request = $this->trace($request, 'RequestId');

        $response = $handler->handle($request);

        return $response->withHeader(self::HEADER, $requestId);
    }

    private function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
