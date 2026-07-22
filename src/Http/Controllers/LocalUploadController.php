<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Credentials\DocumentUploadService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Emulates the client "PUT to S3" step for local/testing/ci only. Registered
 * only when the documents storage driver is local (config/container.php) —
 * impossible to reach in staging/production. The learner still goes through
 * normal auth/permission/CSRF middleware here, unlike a real S3 presigned URL.
 */
final class LocalUploadController
{
    public function __construct(
        private readonly DocumentUploadService $uploads,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function upload(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $authorizationId = (int) ($args['authorizationId'] ?? 0);
        $contents = (string) $request->getBody();

        $result = $this->uploads->receiveLocalUpload(
            $this->auth($request),
            $applicationId,
            $authorizationId,
            $contents,
        );

        return new JsonResponse($result, 200);
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }
}
