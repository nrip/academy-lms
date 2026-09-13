<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\ContentItemCommandService;
use Academy\Application\Courses\CurriculumQueryService;
use Academy\Application\Courses\ModuleCommandService;
use Academy\Application\Learning\LearningMediaIngestService;
use Academy\Domain\Courses\LearningMediaPolicy;
use Academy\Domain\Courses\LessonKind;
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
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class CourseCurriculumController
{
    public function __construct(
        private readonly CurriculumQueryService $query,
        private readonly ModuleCommandService $modules,
        private readonly ContentItemCommandService $contentItems,
        private readonly LearningMediaIngestService $media,
        private readonly LearningMediaPolicy $limits,
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

        try {
            $body = $this->lessonInput($request);
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

        try {
            $body = $this->lessonInput($request);
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
            'uploadLimits' => [
                'pdf' => $this->limits->limitMegabytes('pdf'),
                'audio' => $this->limits->limitMegabytes('audio'),
                'video' => $this->limits->limitMegabytes('video'),
            ],
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
    private function lessonInput(ServerRequestInterface $request): array
    {
        $body = LessonKind::apply($this->body($request));
        $kind = trim((string) ($body['lesson_kind'] ?? ''));
        if ($kind === LessonKind::TEXT) {
            $body['body_text'] = (string) ($body['lesson_body_text'] ?? $body['body_text'] ?? '');
        } elseif ($kind === LessonKind::RICH_TEXT) {
            $body['body_text'] = (string) ($body['lesson_body_rich'] ?? $body['body_text'] ?? '');
        }
        unset($body['lesson_body_text'], $body['lesson_body_rich']);
        $body = $this->normaliseUtcFields($body);
        $fileKind = LessonKind::fileKind($kind);
        $uploaded = $request->getUploadedFiles()['lesson_file'] ?? null;
        if (!$uploaded instanceof UploadedFileInterface || $uploaded->getError() === UPLOAD_ERR_NO_FILE) {
            return $body;
        }
        if ($fileKind === null) {
            throw new ValidationException('This lesson type does not accept a file.');
        }
        $error = $uploaded->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(
                'The file is larger than the server can accept. ' . $this->limits->uploadLimitMessage($fileKind),
            );
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException('The file could not be read. Please try again.');
        }
        $stream = $uploaded->getStream();
        $bytes = $stream->getContents();
        $stored = $this->media->store($fileKind, $bytes, $uploaded->getClientFilename());

        return array_merge($body, $stored);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function normaliseUtcFields(array $body): array
    {
        $india = new DateTimeZone('Asia/Kolkata');
        $utc = new DateTimeZone('UTC');
        foreach (['live_starts_at', 'live_ends_at'] as $key) {
            $value = trim((string) ($body[$key] ?? ''));
            if ($value === '' || preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
                continue;
            }
            $local = false;
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
                $local = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $india);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) === 1) {
                $local = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value, $india);
            }
            if ($local instanceof DateTimeImmutable) {
                $body[$key] = $local->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            }
        }

        return $body;
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
            return 'Lesson saved.';
        }
        if (isset($params['content_deleted'])) {
            return 'Lesson deleted.';
        }

        return null;
    }
}
