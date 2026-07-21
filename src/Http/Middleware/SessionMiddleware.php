<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Http\Security\SessionCookieClearance;
use Academy\Http\Security\SessionCookieSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public const ATTR_SESSION = 'session.record';
    public const ATTR_RAW_TOKEN = 'session.raw_token';
    public const ATTR_RAW_CSRF = 'session.raw_csrf';
    public const ATTR_REQUIRE_SESSION = 'session.require';

    /**
     * @param list<string> $requiredPathPrefixes
     */
    public function __construct(
        private readonly SessionService $sessions,
        private readonly SessionCookieSettings $cookies,
        private readonly array $requiredPathPrefixes = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'Session');

        $cookies = $request->getCookieParams();
        $rawToken = isset($cookies[$this->cookies->sessionCookieName])
            ? (string) $cookies[$this->cookies->sessionCookieName]
            : null;
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ua = $request->getHeaderLine('User-Agent');

        try {
            $loaded = $this->sessions->loadOrCreate(
                $rawToken,
                is_string($ip) ? $ip : null,
                $ua !== '' ? $ua : null,
            );
        } catch (ServiceUnavailableException $exception) {
            if ($this->requiresSession($request)) {
                throw $exception;
            }

            // Public routes may proceed without a session when the store is down.
            return $handler->handle($request);
        }

        $rawCsrf = $loaded['raw_csrf'];
        $csrfCookieOut = false;
        if ($rawCsrf === '') {
            $rawCsrf = isset($cookies[$this->cookies->csrfCookieName])
                ? (string) $cookies[$this->cookies->csrfCookieName]
                : '';
            if ($rawCsrf === '' || !$this->sessions->validateCsrf($loaded['record'], $rawCsrf)) {
                // Cookie lost or mismatch — re-issue CSRF hash without rotating session token.
                $reissued = $this->sessions->reissueCsrf($loaded['record']);
                $loaded['record'] = $reissued['record'];
                $rawCsrf = $reissued['raw_csrf'];
                $csrfCookieOut = true;
            }
        }

        $request = $request
            ->withAttribute(self::ATTR_SESSION, $loaded['record'])
            ->withAttribute(self::ATTR_RAW_TOKEN, $loaded['raw_token'])
            ->withAttribute(self::ATTR_RAW_CSRF, $rawCsrf);

        $response = $handler->handle($request);

        // SessionCookieClearance is authoritative. Do not query the store (e.g. isActive)
        // to decide clearance — Auth already decided; physical revoke is best-effort only.
        $clearance = $request->getAttribute(SessionCookieClearance::ATTR);
        if ($clearance instanceof SessionCookieClearance && $clearance->shouldClear()) {
            return $this->withClearedCookiesOnly($response);
        }

        if ($loaded['set_cookie'] || $rawToken !== $loaded['raw_token']) {
            $response = $this->withSessionCookie($response, $loaded['raw_token']);
            $response = $this->withCsrfCookie($response, $rawCsrf);
        } elseif ($csrfCookieOut || ($rawCsrf !== '' && (!isset($cookies[$this->cookies->csrfCookieName]) || $cookies[$this->cookies->csrfCookieName] !== $rawCsrf))) {
            $response = $this->withCsrfCookie($response, $rawCsrf);
        }

        return $response;
    }

    private function requiresSession(ServerRequestInterface $request): bool
    {
        if ($request->getAttribute(self::ATTR_REQUIRE_SESSION) === true) {
            return true;
        }

        $path = $request->getUri()->getPath();
        foreach ($this->requiredPathPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return str_starts_with($path, '/api/')
            || str_starts_with($path, '/admin/');
    }

    private function withSessionCookie(ResponseInterface $response, string $rawToken): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            $this->cookies->buildSessionSetCookie($rawToken),
        );
    }

    private function withCsrfCookie(ResponseInterface $response, string $rawCsrf): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            $this->cookies->buildCsrfSetCookie($rawCsrf),
        );
    }

    /**
     * Drop any live session/CSRF Set-Cookie headers and emit only clearing directives.
     */
    private function withClearedCookiesOnly(ResponseInterface $response): ResponseInterface
    {
        $kept = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (!$this->isManagedCookieHeader($header)) {
                $kept[] = $header;
            }
        }

        $response = $response->withoutHeader('Set-Cookie');
        foreach ($kept as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }
        foreach ($this->cookies->clearCookieHeaders() as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }

        return $response;
    }

    private function isManagedCookieHeader(string $header): bool
    {
        $name = strtolower(strtok($header, '=') ?: '');

        return $name === strtolower($this->cookies->sessionCookieName)
            || $name === strtolower($this->cookies->csrfCookieName);
    }
}
