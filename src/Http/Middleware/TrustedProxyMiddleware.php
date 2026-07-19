<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies trusted proxy configuration when explicitly configured.
 * Does not invent proxy trust; empty TRUSTED_PROXIES disables rewriting.
 */
final class TrustedProxyMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(
        private readonly array $trustedProxies,
        private readonly bool $forceHttps,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->trustedProxies !== []) {
            $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            if (is_string($remoteAddr) && in_array($remoteAddr, $this->trustedProxies, true)) {
                $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
                if ($forwardedProto !== '') {
                    $uri = $request->getUri()->withScheme(strtolower(trim(explode(',', $forwardedProto)[0])));
                    $request = $request->withUri($uri);
                }

                $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
                if ($forwardedFor !== '') {
                    $clientIp = trim(explode(',', $forwardedFor)[0]);
                    if ($clientIp !== '') {
                        $request = $request->withAttribute('client_ip', $clientIp);
                    }
                }
            }
        }

        if ($this->forceHttps && $request->getUri()->getScheme() !== 'https') {
            $httpsUri = $request->getUri()->withScheme('https');

            return new RedirectResponse((string) $httpsUri, 301);
        }

        return $handler->handle($request);
    }
}
