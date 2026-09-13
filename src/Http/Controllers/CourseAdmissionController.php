<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\AdmissionConfigurationView;
use Academy\Application\Courses\ConfigureCourseAdmissionService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CourseAdmissionController
{
    public function __construct(
        private readonly ConfigureCourseAdmissionService $admission,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);

        return $this->render($request, $this->admission->view($this->auth($request), $courseId, $versionId));
    }

    /**
     * @param array<string, string> $args
     */
    public function saveEligibility(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->admission->saveEligibility($this->auth($request), $courseId, $versionId, $body);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->error($request, $courseId, $versionId, $exception, $body, null);
        }

        return new RedirectResponse($this->path($courseId, $versionId) . '?eligibility_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function addDocument(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->admission->addDocument($this->auth($request), $courseId, $versionId, $body);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->error($request, $courseId, $versionId, $exception, null, $body);
        }

        return new RedirectResponse($this->path($courseId, $versionId) . '?document_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateDocument(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $requirementId = (int) ($args['requirementId'] ?? 0);

        try {
            $this->admission->updateDocument($this->auth($request), $courseId, $versionId, $requirementId, $this->body($request));
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->error($request, $courseId, $versionId, $exception, null, null);
        }

        return new RedirectResponse($this->path($courseId, $versionId) . '?document_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function removeDocument(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $requirementId = (int) ($args['requirementId'] ?? 0);

        try {
            $this->admission->removeDocument($this->auth($request), $courseId, $versionId, $requirementId);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->error($request, $courseId, $versionId, $exception, null, null);
        }

        return new RedirectResponse($this->path($courseId, $versionId) . '?document_removed=1', 303);
    }

    /**
     * @param array<string, mixed>|null $postedEligibility
     * @param array<string, mixed>|null $postedDocument
     */
    private function error(
        ServerRequestInterface $request,
        int $courseId,
        int $versionId,
        \Throwable $exception,
        ?array $postedEligibility,
        ?array $postedDocument,
    ): ResponseInterface {
        $status = $exception instanceof ConflictException ? 409 : ($exception instanceof NotFoundException ? 404 : 422);

        return $this->render(
            $request,
            $this->admission->view($this->auth($request), $courseId, $versionId),
            $exception->getMessage(),
            $status,
            $postedEligibility,
            $postedDocument,
        );
    }

    /**
     * @param array<string, mixed>|null $postedEligibility
     * @param array<string, mixed>|null $postedDocument
     */
    private function render(
        ServerRequestInterface $request,
        AdmissionConfigurationView $view,
        ?string $error = null,
        int $status = 200,
        ?array $postedEligibility = null,
        ?array $postedDocument = null,
    ): ResponseInterface {
        $html = $this->renderer->render('pages/admin/courses/admission', [
            'title' => 'Eligibility and documents',
            'csrf' => $this->csrf($request),
            'view' => $view,
            'error' => $error,
            'flash' => $error === null ? $this->flash($request) : null,
            'postedEligibility' => $postedEligibility,
            'postedDocument' => $postedDocument,
        ]);

        return new HtmlResponse($html, $status);
    }

    private function path(int $courseId, int $versionId): string
    {
        return '/admin/courses/' . $courseId . '/versions/' . $versionId . '/admission';
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }

    private function flash(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        if (isset($params['eligibility_saved'])) {
            return 'Eligibility saved. The public course page uses this text.';
        }
        if (isset($params['document_saved'])) {
            return 'Required document saved. New applications use this list.';
        }
        if (isset($params['document_removed'])) {
            return 'Required document removed.';
        }

        return null;
    }
}
