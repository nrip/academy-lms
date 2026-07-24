<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Ops\EnvironmentCapability;
use Academy\Application\Ops\ReadinessProbe;
use Academy\Http\Middleware\RequestIdMiddleware;
use Academy\Infrastructure\Database\ConnectionFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Liveness and readiness probes (RC-01).
 *
 * GET /health and GET /health/live — process up; no dependency checks.
 * GET /health/ready — DB, schema, writable paths, adapter policy; no secrets.
 */
final class HealthController
{
    /**
     * @param array{
     *   app: array{env: string, debug: bool, url: string},
     *   database: array{host: string, port: int, name: string, user: string, password: string, charset: string, options?: array<int, mixed>},
     *   logging: array{path: string},
     *   security: array<string, mixed>,
     *   paths: array{storage: string}
     * } $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ConnectionFactory $connections,
        private readonly ReadinessProbe $readinessProbe,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->live($request);
    }

    public function live(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, '');

        return new JsonResponse([
            'status' => 'ok',
            'request_id' => $requestId,
        ], 200);
    }

    public function ready(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, '');
        $capability = EnvironmentCapability::fromEnvName((string) ($this->config['app']['env'] ?? 'local'));

        try {
            $pdo = $this->connections->connection();
        } catch (\Throwable) {
            $pdo = null;
        }

        $result = $this->readinessProbe->probe($this->config, $capability, $pdo, true);
        $status = $result['ready'] ? 200 : 503;

        return new JsonResponse([
            'status' => $result['status'],
            'ready' => $result['ready'],
            'checks' => $result['checks'],
            'build' => $result['build'] ?? null,
            'request_id' => $requestId,
        ], $status);
    }
}
