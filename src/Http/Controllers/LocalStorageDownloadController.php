<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Infrastructure\Storage\LocalObjectStorage;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Emulates a signed S3 GET for local/testing/ci only — deliberately
 * unauthenticated (no session/CSRF check), matching how a real S3 presigned
 * URL works: possession of a valid (unexpired, correctly signed) URL is the
 * only credential. Registered only when the documents storage driver is
 * local (config/container.php) — impossible to reach in staging/production.
 */
final class LocalStorageDownloadController
{
    public function __construct(
        private readonly LocalObjectStorage $storage,
    ) {
    }

    public function download(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $objectKey = is_string($query['key'] ?? null) ? $query['key'] : '';
        $expiresAt = is_string($query['exp'] ?? null) ? (int) $query['exp'] : 0;
        $signature = is_string($query['sig'] ?? null) ? $query['sig'] : '';

        if ($objectKey === '' || $signature === '' || $expiresAt < time()) {
            return new EmptyResponse(404);
        }

        if (!$this->storage->verifySignedUrl($objectKey, $expiresAt, $signature)) {
            return new EmptyResponse(404);
        }

        try {
            $contents = $this->storage->readObject($objectKey);
        } catch (\Throwable) {
            return new EmptyResponse(404);
        }

        $response = new Response('php://temp', 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename($objectKey) . '"',
            'Content-Length' => (string) strlen($contents),
        ]);
        $response->getBody()->write($contents);

        return $response;
    }
}
