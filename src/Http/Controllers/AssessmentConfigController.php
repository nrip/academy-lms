<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Assessments\AssessmentConfigService;
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

final class AssessmentConfigController
{
    public function __construct(
        private readonly AssessmentConfigService $assessments,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $contentId = (int) ($args['contentId'] ?? 0);
        $view = $this->assessments->getForContentItem($this->auth($request), $contentId);

        return $this->render($request, $view, null, $this->flash($request));
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $contentId = (int) ($args['contentId'] ?? 0);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $this->assessments->save($this->auth($request), $contentId, $body);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            $view = $this->assessments->getForContentItem($this->auth($request), $contentId);
            $status = $exception instanceof ConflictException ? 409 : 422;

            return $this->render($request, $view, $exception->getMessage(), null, $status, $body);
        }

        return new RedirectResponse('/admin/content-items/' . $contentId . '/assessment?saved=1', 303);
    }

    /**
     * @param array{
     *   course: \Academy\Domain\Courses\Course,
     *   content: \Academy\Domain\Courses\ContentItem,
     *   context: \Academy\Domain\Courses\ContentItemContext,
     *   assessment: ?\Academy\Domain\Assessments\Assessment,
     *   links: list<\Academy\Domain\Assessments\AssessmentQuestionLink>,
     *   linked_questions: list<\Academy\Domain\Assessments\Question>,
     *   bank_questions: list<\Academy\Domain\Assessments\Question>,
     *   editable: bool
     * } $view
     * @param array<string, mixed>|null $posted
     */
    private function render(
        ServerRequestInterface $request,
        array $view,
        ?string $error,
        ?string $flash,
        int $status = 200,
        ?array $posted = null,
    ): ResponseInterface {
        $html = $this->renderer->render('pages/admin/courses/assessment_config', [
            'title' => 'Assessment · ' . $view['content']->title,
            'csrf' => $this->csrf($request),
            'course' => $view['course'],
            'contentItem' => $view['content'],
            'context' => $view['context'],
            'assessment' => $view['assessment'],
            'linkedQuestions' => $view['linked_questions'],
            'bankQuestions' => $view['bank_questions'],
            'editable' => $view['editable'],
            'error' => $error,
            'flash' => $flash,
            'posted' => $posted,
        ]);

        return new HtmlResponse($html, $status);
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
            return 'Assessment configuration saved.';
        }

        return null;
    }
}
