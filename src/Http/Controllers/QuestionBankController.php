<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Assessments\QuestionBankService;
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

final class QuestionBankController
{
    public function __construct(
        private readonly QuestionBankService $questionBanks,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function index(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $view = $this->questionBanks->getBankForCourse($this->auth($request), $courseId);

        return $this->render($request, $view, null, $this->flash($request));
    }

    /**
     * @param array<string, string> $args
     */
    public function create(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->questionBanks->createQuestion($this->auth($request), $courseId, $body);
        } catch (ValidationException | ConflictException $exception) {
            return $this->errorResponse($request, $courseId, $exception, $body);
        }

        return new RedirectResponse('/admin/courses/' . $courseId . '/question-bank?saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $questionId = (int) ($args['questionId'] ?? 0);
        $body = $this->body($request);

        try {
            $this->questionBanks->updateQuestion($this->auth($request), $courseId, $questionId, $body);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $exception, $body);
        }

        return new RedirectResponse('/admin/courses/' . $courseId . '/question-bank?saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $questionId = (int) ($args['questionId'] ?? 0);

        try {
            $this->questionBanks->deleteQuestion($this->auth($request), $courseId, $questionId);
        } catch (ValidationException | ConflictException | NotFoundException $exception) {
            return $this->errorResponse($request, $courseId, $exception, []);
        }

        return new RedirectResponse('/admin/courses/' . $courseId . '/question-bank?deleted=1', 303);
    }

    /**
     * @param array{
     *   course: \Academy\Domain\Courses\Course,
     *   bank: \Academy\Domain\Assessments\QuestionBank,
     *   questions: list<array{question: \Academy\Domain\Assessments\Question, options: list<\Academy\Domain\Assessments\QuestionOption>}>
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
        $html = $this->renderer->render('pages/admin/courses/question_bank', [
            'title' => 'Question bank · ' . $view['course']->masterTitle,
            'csrf' => $this->csrf($request),
            'course' => $view['course'],
            'bank' => $view['bank'],
            'questions' => $view['questions'],
            'error' => $error,
            'flash' => $flash,
            'posted' => $posted,
        ]);

        return new HtmlResponse($html, $status);
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function errorResponse(
        ServerRequestInterface $request,
        int $courseId,
        \Throwable $exception,
        array $posted,
    ): ResponseInterface {
        $view = $this->questionBanks->getBankForCourse($this->auth($request), $courseId);
        $status = $exception instanceof ConflictException ? 409 : 422;

        return $this->render($request, $view, $exception->getMessage(), null, $status, $posted);
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
        if (isset($params['saved'])) {
            return 'Question saved.';
        }
        if (isset($params['deleted'])) {
            return 'Question deleted.';
        }

        return null;
    }
}
