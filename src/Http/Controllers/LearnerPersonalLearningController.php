<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Learning\LearnerPersonalLearningService;
use Academy\Application\Learning\LearnerPlayerQueryService;
use Academy\Application\Learning\LearningQuestionQueryService;
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

final class LearnerPersonalLearningController
{
    public function __construct(
        private readonly LearnerPersonalLearningService $personal,
        private readonly LearnerPlayerQueryService $player,
        private readonly LearningQuestionQueryService $questions,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function addBookmark(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $redirect = '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId;

        try {
            $this->personal->addBookmark($this->auth($request), $enrolmentId, $contentId);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException | NotFoundException $exception) {
            return $this->learnerItemError($request, $enrolmentId, $contentId, $exception);
        }

        return new RedirectResponse($redirect . '?bookmarked=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function removeBookmark(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $redirect = '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId;

        try {
            $this->personal->removeBookmark($this->auth($request), $enrolmentId, $contentId);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException | NotFoundException $exception) {
            return $this->learnerItemError($request, $enrolmentId, $contentId, $exception);
        }

        return new RedirectResponse($redirect . '?unbookmarked=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function saveNote(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $body = (string) (($request->getParsedBody() ?? [])['body'] ?? '');
        $redirect = '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId;

        try {
            $this->personal->saveNote($this->auth($request), $enrolmentId, $contentId, $body);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException | NotFoundException $exception) {
            return $this->learnerItemError($request, $enrolmentId, $contentId, $exception);
        }

        return new RedirectResponse($redirect . '?note_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteNote(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $redirect = '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId;

        try {
            $this->personal->deleteNote($this->auth($request), $enrolmentId, $contentId);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException | NotFoundException $exception) {
            return $this->learnerItemError($request, $enrolmentId, $contentId, $exception);
        }

        return new RedirectResponse($redirect . '?note_deleted=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function saveGoal(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $parsed = $request->getParsedBody();
        $parsed = is_array($parsed) ? $parsed : [];
        $label = (string) ($parsed['label'] ?? '');
        $targetDate = (string) ($parsed['target_date'] ?? '');
        $redirect = '/learning/enrolments/' . $enrolmentId;

        try {
            $this->personal->saveGoal($this->auth($request), $enrolmentId, $label, $targetDate);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException | NotFoundException $exception) {
            return $this->outlineError($request, $enrolmentId, $exception->getMessage());
        }

        return new RedirectResponse($redirect . '?goal_saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function clearGoal(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $redirect = '/learning/enrolments/' . $enrolmentId;

        try {
            $this->personal->clearGoal($this->auth($request), $enrolmentId);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException | NotFoundException $exception) {
            return $this->outlineError($request, $enrolmentId, $exception->getMessage());
        }

        return new RedirectResponse($redirect . '?goal_cleared=1', 303);
    }

    private function learnerItemError(
        ServerRequestInterface $request,
        int $enrolmentId,
        int $contentId,
        \Throwable $exception,
    ): ResponseInterface {
        $auth = $this->auth($request);
        $detail = $this->player->item($auth, $enrolmentId, $contentId);
        $bookmarked = false;
        $noteBody = '';
        try {
            $bookmarked = $this->personal->bookmarkForLesson($auth, $enrolmentId, $contentId) !== null;
            $note = $this->personal->noteForLesson($auth, $enrolmentId, $contentId);
            $noteBody = $note?->body ?? '';
        } catch (AuthorizationException) {
            // Permissions may be missing in edge cases; form still renders.
        }
        $threads = [];
        $canAsk = false;
        try {
            $canAsk = $this->questions->canAskOnLesson($detail->item->contentType);
            $threads = $this->questions->lessonThread($auth, $enrolmentId, $contentId);
        } catch (AuthorizationException) {
            $canAsk = false;
        }

        $html = $this->renderer->render('pages/learning/item', [
            'title' => $detail->item->title,
            'csrf' => $this->csrf($request),
            'detail' => $detail,
            'questions' => $threads,
            'canAsk' => $canAsk,
            'bookmarked' => $bookmarked,
            'noteBody' => $noteBody,
            'flash' => null,
            'error' => $exception->getMessage(),
        ]);

        $status = $exception instanceof AuthorizationException ? 403 : 422;

        return new HtmlResponse($html, $status);
    }

    private function outlineError(ServerRequestInterface $request, int $enrolmentId, string $message): ResponseInterface
    {
        $auth = $this->auth($request);
        $outline = $this->player->outline($auth, $enrolmentId);
        $goal = null;
        $bookmarks = [];
        try {
            $goal = $this->personal->goalForEnrolment($auth, $enrolmentId);
            $bookmarks = $this->personal->bookmarksForEnrolment($auth, $enrolmentId);
        } catch (AuthorizationException) {
            // Keep outline usable.
        }

        $html = $this->renderer->render('pages/learning/outline', [
            'title' => $outline->courseTitle . ' — Learning',
            'csrf' => $this->csrf($request),
            'outline' => $outline,
            'flash' => null,
            'error' => $message,
            'questionSummary' => null,
            'goal' => $goal,
            'bookmarks' => $bookmarks,
        ]);

        return new HtmlResponse($html, 422);
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
