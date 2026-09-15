<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\CourseOperationsQueryService;
use Academy\Application\Learning\LearningQuestionQueryService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FacultyHomeController
{
    public function __construct(
        private readonly CourseOperationsQueryService $operations,
        private readonly LearningQuestionQueryService $questions,
        private readonly AuthorizationService $authorization,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $auth = $this->auth($request);
        $view = $this->operations->facultyHome($auth);
        $openQuestions = [];
        if ($this->authorization->check($auth, 'learning.question.view_scoped')) {
            $courseIds = array_map(static fn ($course): int => $course->courseId, $view->courses);
            $openQuestions = $this->questions->facultyQueue($auth, $courseIds);
        }

        $html = $this->renderer->render('pages/admin/faculty/index', [
            'title' => 'Faculty',
            'csrf' => $this->csrf($request),
            'view' => new \Academy\Application\Courses\FacultyHomeView(
                courses: $view->courses,
                upcomingSessions: $view->upcomingSessions,
                learnersEnrolled: $view->learnersEnrolled,
                recentActivity: $view->recentActivity,
                openQuestions: $openQuestions,
            ),
        ]);

        return new HtmlResponse($html);
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext || !$auth->authenticated) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }
}
