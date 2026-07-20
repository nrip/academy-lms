<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Records observed middleware execution order when X-WP01A-Trace: 1 is present.
 */
trait RecordsMiddlewareOrder
{
    private function trace(ServerRequestInterface $request, string $name): ServerRequestInterface
    {
        $enabled = $request->getAttribute('middleware.trace') === true
            || $request->getHeaderLine('X-WP01A-Trace') === '1';

        if (!$enabled) {
            return $request;
        }

        $request = $request->withAttribute('middleware.trace', true);

        /** @var list<string> $order */
        $order = $request->getAttribute('middleware.observed_order', []);
        $order[] = $name;

        return $request->withAttribute('middleware.observed_order', $order);
    }
}
