<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Credentials\DocumentDownloadService;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\Credentials\PhpUploadRuntimeGuard;
use Academy\Application\Credentials\UploadAuthorizationResult;
use Academy\Domain\Credentials\DocumentFileValidator;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class DocumentController
{
    public function __construct(
        private readonly DocumentUploadService $uploads,
        private readonly DocumentDownloadService $downloads,
    ) {
    }

    /**
     * Multipart form upload from the documents workspace (PRG).
     *
     * @param array<string, string> $args
     */
    public function uploadForm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $requirementId = $this->intField($body, 'requirement_id');
        $replaceSubmissionId = isset($body['replace_submission_id']) && is_string($body['replace_submission_id'])
            && preg_match('/^\d+$/', trim($body['replace_submission_id'])) === 1
            ? (int) trim($body['replace_submission_id'])
            : null;

        try {
            [$filename, $mimeType, $contents] = $this->readUploadedDocument($request);
            $this->uploads->uploadBrowserFile(
                $this->auth($request),
                $applicationId,
                $requirementId,
                $replaceSubmissionId,
                $filename,
                $mimeType,
                $contents,
            );
        } catch (ValidationException $exception) {
            return $this->redirectDocuments($applicationId, $requirementId, $this->firstValidationMessage($exception));
        } catch (DomainRuleException | ConflictException | NotFoundException $exception) {
            return $this->redirectDocuments($applicationId, $requirementId, $exception->getMessage());
        }

        return new RedirectResponse(
            '/applications/' . $applicationId . '/documents?uploaded=1&requirement_id=' . $requirementId,
            303,
        );
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
     * @return array{0: string, 1: string, 2: string} filename, mime, contents
     */
    private function readUploadedDocument(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $uploaded = $files['document'] ?? null;
        if (!$uploaded instanceof UploadedFileInterface) {
            throw new ValidationException('Please choose a file to upload.', [
                'document' => ['Please choose a file to upload.'],
            ]);
        }

        $error = $uploaded->getError();
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException('Please choose a file to upload.', [
                'document' => ['Please choose a file to upload.'],
            ]);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $report = PhpUploadRuntimeGuard::inspect(DocumentFileValidator::PLATFORM_MAX_BYTES);
            $limitMb = PhpUploadRuntimeGuard::formatMb($report['upload_max_bytes']);
            throw new ValidationException('The server cannot accept this file.', [
                'document' => [
                    'The server is currently configured to accept files only up to '
                    . $limitMb
                    . ' MB. Please contact support.',
                ],
            ]);
        }
        if ($error === UPLOAD_ERR_PARTIAL) {
            throw new ValidationException('The file upload was interrupted. Please try again.', [
                'document' => ['The file upload was interrupted. Please try again.'],
            ]);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException('Please choose a file to upload.', [
                'document' => ['The file could not be read. Please try again.'],
            ]);
        }

        $filename = $uploaded->getClientFilename();
        if (!is_string($filename) || trim($filename) === '') {
            throw new ValidationException('Please correct the highlighted fields.', [
                'filename' => ['A valid filename is required.'],
            ]);
        }

        $mimeType = $uploaded->getClientMediaType();
        if (!is_string($mimeType) || trim($mimeType) === '') {
            $mimeType = 'application/octet-stream';
        }

        $stream = $uploaded->getStream();
        $contents = $stream->getContents();
        if ($contents === '') {
            throw new ValidationException('Please choose a file to upload.', [
                'document' => ['Please choose a file to upload.'],
            ]);
        }

        return [trim($filename), strtolower(trim($mimeType)), $contents];
    }

    private function redirectDocuments(int $applicationId, int $requirementId, string $error): RedirectResponse
    {
        return new RedirectResponse(
            '/applications/' . $applicationId . '/documents?error=' . rawurlencode($error)
            . '&requirement_id=' . $requirementId,
            303,
        );
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        $fields = $exception->fields();
        foreach ($fields as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0]) && $messages[0] !== '') {
                return $messages[0];
            }
        }

        $message = $exception->getMessage();

        return $message !== '' ? $message : 'Please correct the highlighted fields.';
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
