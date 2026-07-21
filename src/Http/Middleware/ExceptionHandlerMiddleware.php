<?php

declare(strict_types=1);

namespace Academy\Http\Middleware;

use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\AuthVersionCeilingException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\CsrfException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Exception\ServiceUnavailableException;
use Academy\Domain\Exception\ValidationException;
use Academy\Http\Security\SecurityHeaderPolicy;
use Academy\Http\Security\SessionCookieClearance;
use Academy\Http\Security\SessionCookieSettings;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    use RecordsMiddlewareOrder;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PhpRenderer $renderer,
        private readonly SecurityHeaderPolicy $securityHeaders,
        private readonly SessionCookieSettings $sessionCookies,
        private readonly bool $debug,
        private readonly string $environment,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $this->trace($request, 'ExceptionHandler');

        $clearance = new SessionCookieClearance();
        $request = $request->withAttribute(SessionCookieClearance::ATTR, $clearance);

        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $response = $this->toResponse($request, $exception);
            if ($clearance->shouldClear()) {
                $response = $this->withClearedSessionCookies($response);
            }

            return $this->securityHeaders->apply($request, $response);
        }
    }

    private function withClearedSessionCookies(ResponseInterface $response): ResponseInterface
    {
        $kept = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $name = strtolower(strtok($header, '=') ?: '');
            if ($name !== strtolower($this->sessionCookies->sessionCookieName)
                && $name !== strtolower($this->sessionCookies->csrfCookieName)) {
                $kept[] = $header;
            }
        }

        $response = $response->withoutHeader('Set-Cookie');
        foreach ($kept as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }
        foreach ($this->sessionCookies->clearCookieHeaders() as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }

        return $response;
    }

    private function toResponse(ServerRequestInterface $request, Throwable $exception): ResponseInterface
    {
        $requestId = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, '');
        [$status, $code, $message, $fields, $headers] = $this->mapException($exception);

        $context = [
            'request_id' => $requestId,
            'exception' => $exception::class,
            'status' => $status,
            'path' => $request->getUri()->getPath(),
            'environment' => $this->environment,
        ];

        if ($status >= 500) {
            $this->logger->error($exception->getMessage(), $context + ['trace' => $exception->getTraceAsString()]);
        } else {
            $this->logger->warning($exception->getMessage(), $context);
        }

        $wantsJson = $this->wantsJson($request);

        if ($wantsJson) {
            $payload = [
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
                'request_id' => $requestId,
            ];
            if ($fields !== []) {
                $payload['error']['fields'] = $fields;
            }

            $response = new JsonResponse($payload, $status);
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        }

        $html = $this->renderer->render('pages/error', [
            'title' => 'Error',
            'message' => $message,
            'requestId' => $requestId,
            'status' => $status,
            'debugDetail' => $this->debug ? $exception->getMessage() : null,
        ]);

        $response = new HtmlResponse($html, $status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, list<string>>, 4: array<string, string>}
     */
    private function mapException(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ValidationException => [422, 'VALIDATION_FAILED', $exception->getMessage(), $exception->fields(), []],
            $exception instanceof CsrfException => [403, 'CSRF_FAILED', $exception->getMessage(), [], []],
            $exception instanceof AuthenticationException => [401, 'UNAUTHENTICATED', $exception->getMessage(), [], []],
            $exception instanceof AuthorizationException => [403, 'FORBIDDEN', $exception->getMessage(), [], []],
            $exception instanceof NotFoundException => [404, 'NOT_FOUND', $exception->getMessage(), [], []],
            $exception instanceof ConflictException => [409, 'CONFLICT', $exception->getMessage(), [], []],
            $exception instanceof AuthVersionCeilingException => [409, 'CONFLICT', $exception->getMessage(), [], []],
            $exception instanceof RateLimitExceededException => [
                429,
                'RATE_LIMIT_EXCEEDED',
                $exception->getMessage(),
                [],
                ['Retry-After' => (string) $exception->retryAfterSeconds()],
            ],
            $exception instanceof ServiceUnavailableException => [503, 'SERVICE_UNAVAILABLE', $exception->getMessage(), [], []],
            $exception instanceof DomainRuleException => [422, 'DOMAIN_RULE_VIOLATION', $exception->getMessage(), [], []],
            $exception instanceof ExternalServiceException => [502, 'EXTERNAL_SERVICE_ERROR', 'An upstream service failed.', [], []],
            $exception instanceof \League\Route\Http\Exception\NotFoundException => [404, 'NOT_FOUND', 'Resource not found.', [], []],
            $exception instanceof \League\Route\Http\Exception\MethodNotAllowedException => [405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', [], []],
            default => [
                500,
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                [],
                [],
            ],
        };
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        $path = $request->getUri()->getPath();

        return str_contains($accept, 'application/json')
            || str_starts_with($path, '/health')
            || str_starts_with($path, '/api/')
            || str_starts_with($path, '/admin/');
    }
}
