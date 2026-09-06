<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\AssignCourseAdminScopeService;
use Academy\Application\Courses\CourseAdminQueryService;
use Academy\Application\Courses\CreateCourseService;
use Academy\Application\Courses\UpdateDraftCourseVersionService;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
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

final class CourseAdminController
{
    public function __construct(
        private readonly CourseAdminQueryService $query,
        private readonly CreateCourseService $createCourse,
        private readonly UpdateDraftCourseVersionService $updateDraft,
        private readonly AssignCourseAdminScopeService $assignScope,
        private readonly BatchRepository $batches,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $courses = $this->query->listAssignedCourses($this->auth($request));
        $html = $this->renderer->render('pages/admin/courses/index', [
            'title' => 'Course administration',
            'csrf' => $this->csrf($request),
            'courses' => $courses,
            'flash' => $this->flash($request),
        ]);

        return new HtmlResponse($html);
    }

    public function newForm(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->renderer->render('pages/admin/courses/new', [
            'title' => 'New course',
            'csrf' => $this->csrf($request),
            'values' => [
                'course_code' => '',
                'slug' => '',
                'master_title' => '',
            ],
            'error' => null,
        ]);

        return new HtmlResponse($html);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = [
            'course_code' => (string) ($body['course_code'] ?? ''),
            'slug' => (string) ($body['slug'] ?? ''),
            'master_title' => (string) ($body['master_title'] ?? ''),
        ];

        try {
            $result = $this->createCourse->create(
                $this->auth($request),
                $values['course_code'],
                $values['slug'],
                $values['master_title'],
            );
        } catch (ValidationException | ConflictException $exception) {
            $html = $this->renderer->render('pages/admin/courses/new', [
                'title' => 'New course',
                'csrf' => $this->csrf($request),
                'values' => $values,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, 422);
        }

        return new RedirectResponse(
            '/admin/courses/' . $result['course']->courseId . '/versions/' . $result['version_id'],
            303,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function showCourse(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $detail = $this->query->courseDetail($this->auth($request), $courseId);
        $html = $this->renderer->render('pages/admin/courses/show', [
            'title' => $detail->course->masterTitle,
            'csrf' => $this->csrf($request),
            'detail' => $detail,
            'flash' => $this->flash($request),
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function showVersion(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $detail = $this->query->courseDetail($this->auth($request), $courseId);
        $version = null;
        foreach ($detail->versions as $candidate) {
            if ($candidate->versionId === $versionId) {
                $version = $candidate;
                break;
            }
        }
        if ($version === null) {
            throw new NotFoundException('Course version not found.');
        }

        $html = $this->renderer->render('pages/admin/courses/version', [
            'title' => $version->title,
            'csrf' => $this->csrf($request),
            'course' => $detail->course,
            'version' => $version,
            'batches' => $this->batches->listByCourseVersionId($versionId),
            'error' => null,
            'flash' => $this->flash($request),
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateVersion(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $version = $this->updateDraft->update($this->auth($request), $courseId, $versionId, $body);
        } catch (ValidationException | ConflictException $exception) {
            $detail = $this->query->courseDetail($this->auth($request), $courseId);
            $version = null;
            foreach ($detail->versions as $candidate) {
                if ($candidate->versionId === $versionId) {
                    $version = $candidate;
                    break;
                }
            }
            if ($version === null) {
                throw new NotFoundException('Course version not found.');
            }

            $html = $this->renderer->render('pages/admin/courses/version', [
                'title' => $version->title,
                'csrf' => $this->csrf($request),
                'course' => $detail->course,
                'version' => $version,
                'batches' => $this->batches->listByCourseVersionId($versionId),
                'error' => $exception->getMessage(),
                'flash' => null,
                'posted' => $body,
            ]);

            return new HtmlResponse($html, $exception instanceof ConflictException ? 409 : 422);
        }

        return new RedirectResponse(
            '/admin/courses/' . $courseId . '/versions/' . $version->versionId . '?saved=1',
            303,
        );
    }

    public function scopeForm(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->renderer->render('pages/admin/courses/scope_assign', [
            'title' => 'Assign Course Admin scope',
            'csrf' => $this->csrf($request),
            'values' => [
                'admin_email' => '',
                'course_id' => '',
                'include_future_versions' => '0',
            ],
            'error' => null,
            'flash' => $this->flash($request),
        ]);

        return new HtmlResponse($html);
    }

    public function assignScope(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = [
            'admin_email' => (string) ($body['admin_email'] ?? ''),
            'course_id' => (string) ($body['course_id'] ?? ''),
            'include_future_versions' => isset($body['include_future_versions']) ? '1' : '0',
        ];

        try {
            $this->assignScope->assignCourseScope(
                $this->auth($request),
                $values['admin_email'],
                (int) $values['course_id'],
                $values['include_future_versions'] === '1',
            );
        } catch (ValidationException | ConflictException | NotFoundException | AuthorizationException $exception) {
            $html = $this->renderer->render('pages/admin/courses/scope_assign', [
                'title' => 'Assign Course Admin scope',
                'csrf' => $this->csrf($request),
                'values' => $values,
                'error' => $exception->getMessage(),
                'flash' => null,
            ]);

            return new HtmlResponse($html, 422);
        }

        return new RedirectResponse('/admin/course-admin-scopes?assigned=1', 303);
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
        if (isset($params['saved'])) {
            return 'Draft version saved.';
        }
        if (isset($params['assigned'])) {
            return 'Course Admin scope assigned.';
        }
        if (isset($params['published'])) {
            return 'Course version published. It is now locked and listed in the catalogue.';
        }
        if (isset($params['cloned'])) {
            return 'New Draft CourseVersion created from the selected version.';
        }
        if (isset($params['batch_created'])) {
            return 'Batch created and open for applications.';
        }

        return null;
    }
}
