<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\ContentItemCommandService;
use Academy\Application\Courses\CurriculumQueryService;
use Academy\Application\Courses\ModuleCommandService;
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

final class CourseCurriculumController
{
    public function __construct(
        private readonly CurriculumQueryService $query,
        private readonly ModuleCommandService $modules,
        private readonly ContentItemCommandService $contentItems,
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
        $curriculum = $this->query->getCurriculum($this->auth($request), $courseId, $versionId);

        return $this->renderCurriculum($request, $curriculum, null, $this->flash($request));
    }

    /**
     * @param array<string, string> $args
     */
    public function createModule(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->modules->create($this->auth($request), $courseId, $versionId, $body);
        } catch (ValidationException | ConflictException $exception) {
            return $this->errorResponse($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse($this->curriculumPath($courseId, $versionId) . '?module_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateModule(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $moduleId = (int) ($args['moduleId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->modules->update($this->auth($request), $courseId, $versionId, $moduleId, $body);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse($this->curriculumPath($courseId, $versionId) . '?module_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteModule(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $moduleId = (int) ($args['moduleId'] ?? 0);

        try {
            $this->modules->delete($this->auth($request), $courseId, $versionId, $moduleId);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse($this->curriculumPath($courseId, $versionId) . '?module_deleted=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function createContent(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $moduleId = (int) ($args['moduleId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->contentItems->create($this->auth($request), $courseId, $versionId, $moduleId, $body);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse($this->curriculumPath($courseId, $versionId) . '?content_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateContent(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $moduleId = (int) ($args['moduleId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->contentItems->update(
                $this->auth($request),
                $courseId,
                $versionId,
                $moduleId,
                $contentId,
                $body,
            );
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse($this->curriculumPath($courseId, $versionId) . '?content_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteContent(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $moduleId = (int) ($args['moduleId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);

        try {
            $this->contentItems->delete($this->auth($request), $courseId, $versionId, $moduleId, $contentId);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse($this->curriculumPath($courseId, $versionId) . '?content_deleted=1', 303);
    }

    /**
     * @param array{
     *   course: \Academy\Domain\Courses\Course,
     *   version: \Academy\Domain\Courses\CourseVersion,
     *   modules: list<array{module: \Academy\Domain\Courses\Module, content_items: list<\Academy\Domain\Courses\ContentItem>}>,
     *   editable: bool
     * } $curriculum
     */
    private function renderCurriculum(
        ServerRequestInterface $request,
        array $curriculum,
        ?string $error,
        ?string $flash,
        int $status = 200,
    ): ResponseInterface {
        $html = $this->renderer->render('pages/admin/courses/curriculum', [
            'title' => 'Curriculum · ' . $curriculum['version']->title,
            'csrf' => $this->csrf($request),
            'course' => $curriculum['course'],
            'version' => $curriculum['version'],
            'modules' => $curriculum['modules'],
            'editable' => $curriculum['editable'],
            'error' => $error,
            'flash' => $flash,
        ]);

        return new HtmlResponse($html, $status);
    }

    private function errorResponse(
        ServerRequestInterface $request,
        int $courseId,
        int $versionId,
        \Throwable $exception,
    ): ResponseInterface {
        $curriculum = $this->query->getCurriculum($this->auth($request), $courseId, $versionId);
        $status = $exception instanceof ConflictException ? 409 : 422;

        return $this->renderCurriculum($request, $curriculum, $exception->getMessage(), null, $status);
    }

    private function curriculumPath(int $courseId, int $versionId): string
    {
        return '/admin/courses/' . $courseId . '/versions/' . $versionId . '/curriculum';
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
        if (isset($params['module_saved'])) {
            return 'Module saved.';
        }
        if (isset($params['module_deleted'])) {
            return 'Module deleted.';
        }
        if (isset($params['content_saved'])) {
            return 'Content item saved.';
        }
        if (isset($params['content_deleted'])) {
            return 'Content item deleted.';
        }

        return null;
    }
}
