<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Testing-only RBAC probes under /admin/__wp01b/* — registered only when APP_ENV=testing.
 */
final class Wp01bRbacProbeController
{
    public function allow(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok($request, 'allow');
    }

    public function deny(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok($request, 'deny');
    }

    public function document(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok($request, 'document');
    }

    public function refund(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok($request, 'refund');
    }

    /**
     * @param array<string, string> $args
     */
    public function parameterized(ServerRequestInterface $request, array $args): ResponseInterface
    {
        return $this->ok($request, 'parameterized', ['id' => (string) ($args['id'] ?? '')]);
    }

    public function unknown(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok($request, 'unknown');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function ok(ServerRequestInterface $request, string $probe, array $extra = []): ResponseInterface
    {
        /** @var AuthContext|null $auth */
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        /** @var list<string> $order */
        $order = $request->getAttribute('middleware.observed_order', []);

        return new JsonResponse([
            'ok' => true,
            'probe' => $probe,
            'observed_order' => $order,
            'auth' => $auth instanceof AuthContext ? [
                'user_id' => $auth->userId,
                'authenticated' => $auth->authenticated,
                'auth_stage' => $auth->authStage,
                'has_privileged_role' => $auth->hasPrivilegedRole,
            ] : null,
        ] + $extra);
    }
}
