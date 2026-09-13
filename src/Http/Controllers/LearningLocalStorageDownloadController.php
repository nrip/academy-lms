<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Infrastructure\Storage\LearningLocalObjectStorage;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves a signed learning-media GET. Possession of a valid signature is the
 * credential, matching a private S3 presigned URL. Registered only when
 * LEARNING_STORAGE_DRIVER=local. Never reads public/ or credential documents.
 */
final class LearningLocalStorageDownloadController
{
    public function __construct(
        private readonly LearningLocalObjectStorage $storage,
    ) {
    }

    public function download(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $objectKey = is_string($query['key'] ?? null) ? $query['key'] : '';
        $expiresAt = is_string($query['exp'] ?? null) ? (int) $query['exp'] : 0;
        $signature = is_string($query['sig'] ?? null) ? $query['sig'] : '';

        if ($objectKey === ''
            || $signature === ''
            || $expiresAt < time()
            || !str_starts_with($objectKey, 'learning/media/')
        ) {
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
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->getBody()->write($contents);

        return $response;
    }
}
