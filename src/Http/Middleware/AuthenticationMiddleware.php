<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\UserSecuritySnapshotRepository;
use Academy\Domain\Security\AuthContext;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Security\SessionCookieClearance;
use Academy\Http\Security\SessionCookieSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Authentication foundation with security-snapshot validation (WP-01B-1).
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public const ATTR_AUTH = 'auth.context';
    public const ATTR_CLEAR_SESSION_COOKIES = 'auth.clear_session_cookies';

    public function __construct(
        private readonly UserSecuritySnapshotRepository $snapshots,
        private readonly SessionService $sessions,
        private readonly SessionCookieSettings $cookies,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'Authentication');

        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);

        if (!$session instanceof SessionRecord) {
            return $handler->handle($request->withAttribute(self::ATTR_AUTH, null));
        }

        if ($session->userId === null) {
            return $handler->handle($request->withAttribute(self::ATTR_AUTH, AuthContext::guest($session->sessionId)));
        }

        try {
            $snapshot = $this->snapshots->findById($session->userId);
        } catch (Throwable) {
            // Bound session + security store failure always fails closed with 503.
            throw new ServiceUnavailableException('Security store unavailable.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $invalidate = $snapshot === null
            || $snapshot->isSuspended()
            || $snapshot->isTemporarilyLocked($now)
            || $session->authVersion === null
            || $session->authVersion !== $snapshot->authVersion;

        if ($invalidate) {
            try {
                $this->sessions->revoke($session);
            } catch (Throwable) {
                // Best-effort revoke; continue as guest.
            }

            // Mark clearance before calling downstream so ExceptionHandler can still
            // emit Max-Age=0 cookies when Permission/CSRF throw on the guest context.
            $clearance = $request->getAttribute(SessionCookieClearance::ATTR);
            if ($clearance instanceof SessionCookieClearance) {
                $clearance->requestClear();
            }

            $response = $handler->handle(
                $request
                    ->withAttribute(SessionMiddleware::ATTR_SESSION, null)
                    ->withAttribute(self::ATTR_AUTH, AuthContext::guest($session->sessionId))
                    ->withAttribute(self::ATTR_CLEAR_SESSION_COOKIES, true),
            );

            return $this->clearCookies($response);
        }

        $rawStage = null;
        if (isset($session->payload['auth_stage']) && is_string($session->payload['auth_stage'])) {
            $rawStage = $session->payload['auth_stage'];
        }
        $authStage = AuthStage::resolveEffective($rawStage, $snapshot->hasPrivilegedRole);

        $context = AuthContext::authenticated(
            userId: $snapshot->userId,
            sessionId: $session->sessionId,
            authStage: $authStage,
            authVersion: $snapshot->authVersion,
            hasPrivilegedRole: $snapshot->hasPrivilegedRole,
            accountStatus: $snapshot->accountStatus,
        );

        return $handler->handle($request->withAttribute(self::ATTR_AUTH, $context));
    }

    private function clearCookies(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->cookies->clearCookieHeaders() as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }

        return $response;
    }
}
