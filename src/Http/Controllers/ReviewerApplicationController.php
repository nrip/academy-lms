<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Credentials\DocumentDownloadService;
use Academy\Application\Review\ApplicationCorrectionRequestService;
use Academy\Application\Review\ApplicationDecisionService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\ReviewerApplicationQueryService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Domain\Credentials\ApplicationRejectionReasonCode;
use Academy\Domain\Credentials\DocumentRejectionReasonCode;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Review\ReviewerQueueFilter;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ReviewerApplicationController
{
    public function __construct(
        private readonly ReviewerApplicationQueryService $queries,
        private readonly ReviewerClaimService $claims,
        private readonly DocumentReviewService $documentReview,
        private readonly ApplicationCorrectionRequestService $corrections,
        private readonly ApplicationDecisionService $decisions,
        private readonly DocumentDownloadService $downloads,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function queue(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filter = isset($query['filter']) ? (string) $query['filter'] : null;
        $page = isset($query['page']) ? (int) $query['page'] : 1;

        $result = $this->queries->queue($this->auth($request), $filter, max(1, $page));

        $html = $this->renderer->render('pages/reviewer/queue', [
            'title' => 'Reviewer queue',
            'csrf' => $this->csrf($request),
            'page' => $result,
            'filters' => ReviewerQueueFilter::ALL,
            'authUserId' => $this->auth($request)->userId,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $query = $request->getQueryParams();
        $detail = $this->queries->detail($this->auth($request), $applicationId);

        $html = $this->renderer->render('pages/reviewer/show', [
            'title' => 'Review application',
            'csrf' => $this->csrf($request),
            'view' => $detail,
            'authUserId' => $this->auth($request)->userId,
            'flashOk' => isset($query['ok']) ? (string) $query['ok'] : null,
            'documentReasonCodes' => DocumentRejectionReasonCode::ALL,
            'applicationReasonCodes' => ApplicationRejectionReasonCode::ALL,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function claim(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $this->claims->claim($this->auth($request), $applicationId);

        return $this->detailRedirect($request, $applicationId, 'claimed');
    }

    /**
     * @param array<string, string> $args
     */
    public function release(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->claims->release(
            $this->auth($request),
            $applicationId,
            $this->intField($body, 'assignment_row_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'released');
    }

    /**
     * @param array<string, string> $args
     */
    public function verifyDocument(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $submissionId = (int) ($args['submissionId'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->documentReview->verify(
            $this->auth($request),
            $applicationId,
            $submissionId,
            $this->optionalStringField($body, 'internal_note'),
            $this->optionalIntField($body, 'row_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'verified');
    }

    /**
     * @param array<string, string> $args
     */
    public function rejectDocument(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $submissionId = (int) ($args['submissionId'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->documentReview->reject(
            $this->auth($request),
            $applicationId,
            $submissionId,
            $this->stringField($body, 'reason_code'),
            $this->optionalStringField($body, 'learner_visible_message'),
            $this->optionalStringField($body, 'internal_note'),
            $this->optionalIntField($body, 'row_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'rejected');
    }

    /**
     * @param array<string, string> $args
     */
    public function requestDocumentResubmission(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $submissionId = (int) ($args['submissionId'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->documentReview->requestResubmission(
            $this->auth($request),
            $applicationId,
            $submissionId,
            $this->stringField($body, 'reason_code'),
            $this->optionalStringField($body, 'learner_visible_message'),
            $this->optionalStringField($body, 'internal_note'),
            $this->optionalIntField($body, 'row_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'resubmission_requested');
    }

    /**
     * @param array<string, string> $args
     */
    public function requestCorrection(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->corrections->requestCorrection(
            $this->auth($request),
            $applicationId,
            $this->intListField($body, 'requirement_ids'),
            $this->stringField($body, 'reason_code'),
            $this->optionalStringField($body, 'learner_visible_message'),
            $this->optionalStringField($body, 'internal_note'),
            $this->intField($body, 'state_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'correction_requested');
    }

    /**
     * @param array<string, string> $args
     */
    public function approve(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->decisions->approve(
            $this->auth($request),
            $applicationId,
            $this->intField($body, 'state_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'approved');
    }

    /**
     * @param array<string, string> $args
     */
    public function reject(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        $this->decisions->reject(
            $this->auth($request),
            $applicationId,
            $this->stringField($body, 'reason_code'),
            $this->optionalStringField($body, 'learner_visible_message'),
            $this->optionalStringField($body, 'internal_note'),
            $this->intField($body, 'state_version'),
        );

        return $this->detailRedirect($request, $applicationId, 'application_rejected');
    }

    /**
     * @param array<string, string> $args
     */
    public function downloadDocument(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $submissionId = (int) ($args['submissionId'] ?? 0);

        $result = $this->downloads->getReviewerSignedDownloadUrl(
            $this->auth($request),
            $applicationId,
            $submissionId,
        );

        if ($this->wantsJson($request)) {
            return new JsonResponse(['url' => $result['url']]);
        }

        return new RedirectResponse($result['url'], 303);
    }

    private function detailRedirect(
        ServerRequestInterface $request,
        int $applicationId,
        string $ok,
    ): ResponseInterface {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['ok' => true, 'flash' => $ok], 200);
        }

        return new RedirectResponse(
            '/reviewer/applications/' . $applicationId . '?ok=' . rawurlencode($ok),
            303,
        );
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
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

        throw new ValidationException('Please provide valid review details.', [
            $key => ['A valid ' . $key . ' is required.'],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalIntField(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ValidationException('Please provide valid review details.', [
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
            throw new ValidationException('Please provide valid review details.', [
                $key => ['A valid ' . $key . ' is required.'],
            ]);
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalStringField(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<int>
     */
    private function intListField(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            throw new ValidationException('Please select at least one document requirement.', [
                $key => ['At least one requirement is required.'],
            ]);
        }

        if (!is_array($value)) {
            return [$this->intField([$key => $value], $key)];
        }

        if ($value === []) {
            throw new ValidationException('Please select at least one document requirement.', [
                $key => ['At least one requirement is required.'],
            ]);
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $ids[] = $item;
                continue;
            }
            if (is_string($item) && preg_match('/^\d+$/', trim($item)) === 1) {
                $ids[] = (int) trim($item);
            }
        }

        if ($ids === []) {
            throw new ValidationException('Please select at least one document requirement.', [
                $key => ['At least one requirement is required.'],
            ]);
        }

        return $ids;
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
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
