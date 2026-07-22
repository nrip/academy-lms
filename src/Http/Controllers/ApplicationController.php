<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\ApplicationSubmitService;
use Academy\Application\Admissions\ApplicationWorkspaceService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Domain\Admissions\ApplicationDraftFactory;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApplicationController
{
    public function __construct(
        private readonly DraftApplicationService $applications,
        private readonly ApplicationDraftFactory $draftFactory,
        private readonly ApplicationWorkspaceService $workspace,
        private readonly ApplicationDeclarationService $declarations,
        private readonly ApplicationSubmitService $submissions,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $batchId = $this->draftFactory->batchIdFromInput($body);

        $application = $this->applications->createDraft($this->auth($request), $batchId);

        return new RedirectResponse('/applications/' . $application->applicationId, 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $view = $this->workspace->getOwn($this->auth($request), $applicationId);

        $html = $this->renderer->render('pages/applications/show', [
            'title' => 'My application',
            'csrf' => $this->csrf($request),
            'view' => $view,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $view = $this->workspace->getOwn($this->auth($request), $applicationId);

        return $this->renderEdit($request, $view, null, 200);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateDeclaration(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);

        try {
            $this->declarations->acceptOnDraft($this->auth($request), $applicationId);
        } catch (DomainRuleException|ConflictException $exception) {
            $view = $this->workspace->getOwn($this->auth($request), $applicationId);

            return $this->renderEdit($request, $view, $exception->getMessage(), $exception instanceof ConflictException ? 409 : 422);
        }

        return new RedirectResponse('/applications/' . $applicationId . '/documents', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function documents(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $view = $this->workspace->getOwn($this->auth($request), $applicationId);

        $html = $this->renderer->render('pages/applications/documents', [
            'title' => 'Application documents',
            'csrf' => $this->csrf($request),
            'view' => $view,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);

        try {
            $this->submissions->submit($this->auth($request), $applicationId);
        } catch (DomainRuleException|ConflictException) {
            return new RedirectResponse('/applications/' . $applicationId . '/submission-result?ok=0', 303);
        }

        return new RedirectResponse('/applications/' . $applicationId . '/submission-result?ok=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function submissionResult(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $view = $this->workspace->getOwn($this->auth($request), $applicationId);
        $query = $request->getQueryParams();
        $ok = isset($query['ok']) && (string) $query['ok'] === '1';

        $html = $this->renderer->render('pages/applications/submission_result', [
            'title' => 'Submission result',
            'view' => $view,
            'ok' => $ok,
        ]);

        return new HtmlResponse($html);
    }

    private function renderEdit(ServerRequestInterface $request, mixed $view, ?string $error, int $status): ResponseInterface
    {
        $html = $this->renderer->render('pages/applications/edit', [
            'title' => 'Edit application',
            'csrf' => $this->csrf($request),
            'view' => $view,
            'error' => $error,
        ]);

        return new HtmlResponse($html, $status);
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }
}
