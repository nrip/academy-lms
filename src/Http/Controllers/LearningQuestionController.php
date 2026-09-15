<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Learning\AskLearningQuestionService;
use Academy\Application\Learning\CloseLearningQuestionService;
use Academy\Application\Learning\LearningQuestionQueryService;
use Academy\Application\Learning\LearnerPlayerQueryService;
use Academy\Application\Learning\RespondToLearningQuestionService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
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

final class LearningQuestionController
{
    public function __construct(
        private readonly AskLearningQuestionService $ask,
        private readonly RespondToLearningQuestionService $respond,
        private readonly CloseLearningQuestionService $close,
        private readonly LearningQuestionQueryService $questions,
        private readonly LearnerPlayerQueryService $player,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function ask(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $body = (string) (($request->getParsedBody() ?? [])['body'] ?? '');
        $redirect = '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId;

        try {
            $this->ask->ask($this->auth($request), $enrolmentId, $contentId, $body);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException $exception) {
            return $this->learnerItemError($request, $enrolmentId, $contentId, $exception);
        }

        return new RedirectResponse($redirect . '?asked=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function closeOwn(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $questionId = (int) ($args['questionId'] ?? 0);
        $redirect = '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId;

        try {
            $this->close->close($this->auth($request), $questionId);
        } catch (ValidationException | DomainRuleException | AuthorizationException | NotFoundException $exception) {
            return $this->learnerItemError($request, $enrolmentId, $contentId, $exception);
        }

        return new RedirectResponse($redirect . '?closed=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function facultyShow(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $questionId = (int) ($args['questionId'] ?? 0);
        $params = $request->getQueryParams();
        $flash = null;
        if (isset($params['responded'])) {
            $flash = 'Response posted.';
        } elseif (isset($params['closed'])) {
            $flash = 'Question closed.';
        }

        $detail = $this->questions->facultyDetail($this->auth($request), $questionId);
        $html = $this->renderer->render('pages/admin/faculty/question', [
            'title' => 'Question',
            'csrf' => $this->csrf($request),
            'detail' => $detail,
            'flash' => $flash,
            'error' => null,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function facultyRespond(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $questionId = (int) ($args['questionId'] ?? 0);
        $body = (string) (($request->getParsedBody() ?? [])['body'] ?? '');

        try {
            $this->respond->respond($this->auth($request), $questionId, $body);
        } catch (ValidationException | DomainRuleException | AuthorizationException | NotFoundException $exception) {
            $detail = $this->questions->facultyDetail($this->auth($request), $questionId);
            $html = $this->renderer->render('pages/admin/faculty/question', [
                'title' => 'Question',
                'csrf' => $this->csrf($request),
                'detail' => $detail,
                'flash' => null,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, 422);
        }

        return new RedirectResponse('/faculty/questions/' . $questionId . '?responded=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function facultyClose(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $questionId = (int) ($args['questionId'] ?? 0);

        try {
            $this->close->close($this->auth($request), $questionId);
        } catch (ValidationException | DomainRuleException | AuthorizationException | NotFoundException $exception) {
            $detail = $this->questions->facultyDetail($this->auth($request), $questionId);
            $html = $this->renderer->render('pages/admin/faculty/question', [
                'title' => 'Question',
                'csrf' => $this->csrf($request),
                'detail' => $detail,
                'flash' => null,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, 422);
        }

        return new RedirectResponse('/faculty/questions/' . $questionId . '?closed=1', 303);
    }

    private function learnerItemError(
        ServerRequestInterface $request,
        int $enrolmentId,
        int $contentId,
        \Throwable $exception,
    ): ResponseInterface {
        try {
            $detail = $this->player->item($this->auth($request), $enrolmentId, $contentId);
            $threads = $this->questions->lessonThread($this->auth($request), $enrolmentId, $contentId);
            $html = $this->renderer->render('pages/learning/item', [
                'title' => $detail->item->title,
                'csrf' => $this->csrf($request),
                'detail' => $detail,
                'questions' => $threads,
                'canAsk' => $this->questions->canAskOnLesson($detail->item->contentType),
                'flash' => null,
                'error' => $exception->getMessage(),
            ]);

            $status = $exception instanceof ConflictException ? 409 : 422;
            if ($exception instanceof AuthorizationException) {
                $status = 403;
            }

            return new HtmlResponse($html, $status);
        } catch (NotFoundException | ConflictException | AuthorizationException) {
            throw $exception;
        }
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
