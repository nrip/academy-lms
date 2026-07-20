<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Domain\Security\AuthContext;
use Academy\Domain\Security\SessionRecord;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authentication foundation: attach AuthContext when session is user-bound.
 * Login/RBAC are WP-01B.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public const ATTR_AUTH = 'auth.context';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'Authentication');

        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);

        if ($session instanceof SessionRecord && $session->userId !== null) {
            $context = AuthContext::authenticated($session->userId, $session->sessionId);
        } elseif ($session instanceof SessionRecord) {
            $context = AuthContext::guest($session->sessionId);
        } else {
            $context = null;
        }

        return $handler->handle($request->withAttribute(self::ATTR_AUTH, $context));
    }
}
