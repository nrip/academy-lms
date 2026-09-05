<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Assessments\AssessmentAttemptQueryService;
use Academy\Application\Assessments\SaveAssessmentResponsesService;
use Academy\Application\Assessments\StartAssessmentAttemptService;
use Academy\Application\Assessments\SubmitAssessmentAttemptService;
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

final class AssessmentAttemptController
{
    public function __construct(
        private readonly StartAssessmentAttemptService $start,
        private readonly SaveAssessmentResponsesService $save,
        private readonly SubmitAssessmentAttemptService $submit,
        private readonly AssessmentAttemptQueryService $query,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function start(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $assessmentId = (int) ($args['assessmentId'] ?? 0);

        try {
            $attempt = $this->start->start($this->auth($request), $enrolmentId, $assessmentId);
        } catch (ConflictException $exception) {
            if (str_contains($exception->getMessage(), 'in-progress')) {
                $existingId = $this->query->findInProgressAttemptId(
                    $this->auth($request),
                    $enrolmentId,
                    $assessmentId,
                );
                if ($existingId !== null) {
                    return new RedirectResponse('/learning/attempts/' . $existingId, 303);
                }
            }

            return $this->startErrorRedirect($enrolmentId, $exception);
        } catch (ValidationException | DomainRuleException | AuthorizationException | NotFoundException $exception) {
            return $this->startErrorRedirect($enrolmentId, $exception);
        }

        return new RedirectResponse('/learning/attempts/' . $attempt->attemptId, 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $attemptId = (int) ($args['attemptId'] ?? 0);
        $view = $this->query->getAttemptView($this->auth($request), $attemptId);
        $params = $request->getQueryParams();
        $flash = null;
        if (isset($params['saved'])) {
            $flash = 'Answers saved.';
        }
        if (isset($params['submitted'])) {
            $flash = 'Attempt submitted.';
        }

        $html = $this->renderer->render('pages/learning/attempt', [
            'title' => $view->assessment->title,
            'csrf' => $this->csrf($request),
            'view' => $view,
            'flash' => $flash,
            'error' => null,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function saveResponses(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $attemptId = (int) ($args['attemptId'] ?? 0);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        /** @var array<string, mixed> $answers */
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];

        try {
            $this->save->save($this->auth($request), $attemptId, $answers);
        } catch (ValidationException | ConflictException | AuthorizationException $exception) {
            $view = $this->query->getAttemptView($this->auth($request), $attemptId);
            $html = $this->renderer->render('pages/learning/attempt', [
                'title' => $view->assessment->title,
                'csrf' => $this->csrf($request),
                'view' => $view,
                'flash' => null,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, $exception instanceof ConflictException ? 409 : 422);
        }

        return new RedirectResponse('/learning/attempts/' . $attemptId . '?saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $attemptId = (int) ($args['attemptId'] ?? 0);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        /** @var array<string, mixed> $answers */
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];

        try {
            if ($answers !== []) {
                $this->save->save($this->auth($request), $attemptId, $answers);
            }
            $this->submit->submit($this->auth($request), $attemptId);
        } catch (ValidationException | ConflictException | DomainRuleException | AuthorizationException $exception) {
            $view = $this->query->getAttemptView($this->auth($request), $attemptId);
            $html = $this->renderer->render('pages/learning/attempt', [
                'title' => $view->assessment->title,
                'csrf' => $this->csrf($request),
                'view' => $view,
                'flash' => null,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, $exception instanceof ConflictException ? 409 : 422);
        }

        return new RedirectResponse('/learning/attempts/' . $attemptId . '?submitted=1', 303);
    }

    private function startErrorRedirect(int $enrolmentId, \Throwable $exception): ResponseInterface
    {
        $msg = rawurlencode($exception->getMessage());

        return new RedirectResponse(
            '/learning/enrolments/' . $enrolmentId . '?assessment_error=' . $msg,
            303,
        );
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
}
