<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Domain\Courses\LearningMediaPolicy;
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

        $mime = (new LearningMediaPolicy())->storedMime($contents) ?? 'application/octet-stream';
        $length = strlen($contents);
        $start = 0;
        $end = $length - 1;
        $status = 200;
        $range = $request->getHeaderLine('Range');
        if ($range !== '' && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $match) === 1) {
            $start = (int) $match[1];
            $end = $match[2] === '' ? $length - 1 : (int) $match[2];
            if ($start > $end || $start >= $length) {
                return new Response('php://temp', 416, [
                    'Content-Range' => 'bytes */' . $length,
                ]);
            }
            $end = min($end, $length - 1);
            $contents = substr($contents, $start, $end - $start + 1);
            $status = 206;
        }

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $length;
        }

        $response = new Response('php://temp', $status, $headers);
        $response->getBody()->write($contents);

        return $response;
    }
}
