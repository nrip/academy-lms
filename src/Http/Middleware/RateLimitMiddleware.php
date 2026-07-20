<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Application\Security\RateLimiter;
use Academy\Domain\Security\AuthContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RateLimitMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    /**
     * @param list<string> $exemptPathPrefixes
     * @param array<string, string> $pathPolicies
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly array $exemptPathPrefixes = ['/webhooks/', '/health'],
        private readonly array $pathPolicies = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'RateLimit');

        $path = $request->getUri()->getPath();
        foreach ($this->exemptPathPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        $method = strtoupper($request->getMethod());
        $pathPolicyKey = $method . ' ' . $path;
        if (isset($this->pathPolicies[$pathPolicyKey])) {
            $this->applyNamedPolicy($request, $this->pathPolicies[$pathPolicyKey]);

            return $handler->handle($request);
        }

        $routePolicy = $request->getAttribute('rate_limit.policy');
        if (is_string($routePolicy) && $routePolicy !== '') {
            $this->applyNamedPolicy($request, $routePolicy);

            return $handler->handle($request);
        }

        // Default authenticated mutating actions
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            /** @var AuthContext|null $auth */
            $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
            if ($auth instanceof AuthContext && $auth->authenticated && $auth->userId !== null) {
                $this->limiter->hit('authenticated.default', [
                    ['type' => 'user', 'value' => (string) $auth->userId],
                ]);
            }
        }

        return $handler->handle($request);
    }

    private function applyNamedPolicy(ServerRequestInterface $request, string $policyKey): void
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $dimensions = $request->getAttribute('rate_limit.dimensions');
        if (!is_array($dimensions) || $dimensions === []) {
            $dimensions = [['type' => 'ip', 'value' => is_string($ip) ? $ip : '0.0.0.0']];
        }

        /** @var list<array{type: string, value: string}> $dimensions */
        $this->limiter->hit($policyKey, $dimensions);
    }
}
