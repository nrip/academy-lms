<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Credentials\DocumentDownloadService;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\Credentials\UploadAuthorizationResult;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DocumentController
{
    public function __construct(
        private readonly DocumentUploadService $uploads,
        private readonly DocumentDownloadService $downloads,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function authorizeUpload(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        $result = $this->uploads->authorizeUpload(
            $this->auth($request),
            $applicationId,
            $this->intField($body, 'requirement_id'),
            $this->stringField($body, 'filename'),
            $this->stringField($body, 'mime_type'),
            $this->intField($body, 'size_bytes'),
        );

        return new JsonResponse($this->authorizationPayload($result), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function confirm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        $document = $this->uploads->confirmUpload(
            $this->auth($request),
            $applicationId,
            $this->intField($body, 'requirement_id'),
            $this->stringField($body, 'object_key'),
            $this->stringField($body, 'checksum_sha256'),
        );

        return new JsonResponse([
            'document_submission_id' => $document->documentSubmissionId,
            'requirement_id' => $document->requirementId,
            'status' => $document->status,
            'scan_status' => $document->scanStatus,
        ], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function replace(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $currentSubmissionId = (int) ($args['submissionId'] ?? 0);
        $body = (array) $request->getParsedBody();

        $result = $this->uploads->replaceUpload(
            $this->auth($request),
            $applicationId,
            $this->intField($body, 'requirement_id'),
            $currentSubmissionId,
            $this->stringField($body, 'filename'),
            $this->stringField($body, 'mime_type'),
            $this->intField($body, 'size_bytes'),
        );

        return new JsonResponse($this->authorizationPayload($result), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function download(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $submissionId = (int) ($args['submissionId'] ?? 0);

        $result = $this->downloads->getOwnSignedDownloadUrl($this->auth($request), $applicationId, $submissionId);

        return new RedirectResponse($result['url'], 303);
    }

    /**
     * @return array{authorization_id: int, requirement_id: int, object_key: string, upload_url: string, method: string, headers: array<string, string>, expires_at: string}
     */
    private function authorizationPayload(UploadAuthorizationResult $result): array
    {
        return [
            'authorization_id' => $result->authorizationId,
            'requirement_id' => $result->requirementId,
            'object_key' => $result->objectKey,
            'upload_url' => $result->uploadUrl,
            'method' => $result->method,
            'headers' => $result->headers,
            'expires_at' => $result->expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ValidationException('Please provide valid document details.', [
            $key => ['A valid ' . $key . ' is required.'],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new ValidationException('Please provide valid document details.', [
                $key => ['A valid ' . $key . ' is required.'],
            ]);
        }

        return trim($value);
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
