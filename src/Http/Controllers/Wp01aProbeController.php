<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Security\AuthContext;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * WP-01A probe endpoints for foundational security tests only.
 */
final class Wp01aProbeController
{
    public function probe(ServerRequestInterface $request): ResponseInterface
    {
        /** @var list<string> $order */
        $order = $request->getAttribute('middleware.observed_order', []);

        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        /** @var AuthContext|null $auth */
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);

        return new JsonResponse([
            'ok' => true,
            'method' => $request->getMethod(),
            'session_id' => $session?->sessionId,
            'authenticated' => $auth !== null && $auth->authenticated,
            'csrf' => (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, ''),
            'observed_order' => $order,
        ]);
    }

    public function protected(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        if ($session === null) {
            throw new ServiceUnavailableException('Session required.');
        }

        return new JsonResponse(['ok' => true, 'session_id' => $session->sessionId]);
    }

    public function limited(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['ok' => true]);
    }
}
