<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\CsrfException;
use Academy\Domain\Security\SessionRecord;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CsrfMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var list<string> */
    private array $exemptPaths;

    /**
     * @param list<string> $exemptPaths
     */
    public function __construct(
        private readonly SessionService $sessions,
        array $exemptPaths = ['/health', '/webhooks/'],
    ) {
        $this->exemptPaths = $exemptPaths;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'Csrf');

        if (!$this->requiresValidation($request)) {
            return $handler->handle($request);
        }

        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        if (!$session instanceof SessionRecord) {
            throw new CsrfException('CSRF validation failed: no session.');
        }

        $submitted = $this->extractToken($request);
        if (!$this->sessions->validateCsrf($session, $submitted)) {
            throw new CsrfException();
        }

        return $handler->handle($request);
    }

    private function requiresValidation(ServerRequestInterface $request): bool
    {
        if (!in_array(strtoupper($request->getMethod()), self::UNSAFE, true)) {
            return false;
        }

        $path = $request->getUri()->getPath();
        foreach ($this->exemptPaths as $exempt) {
            if ($path === $exempt || str_starts_with($path, $exempt)) {
                return false;
            }
        }

        return true;
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('X-CSRF-Token');
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['_csrf']) && is_string($body['_csrf'])) {
            return $body['_csrf'];
        }

        return null;
    }
}
