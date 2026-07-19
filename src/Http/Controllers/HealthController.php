<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Http\Middleware\RequestIdMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Minimal health endpoint — no configuration, versions, env, or database details.
 */
final class HealthController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, '');

        return new JsonResponse([
            'status' => 'ok',
            'request_id' => $requestId,
        ], 200);
    }
}
