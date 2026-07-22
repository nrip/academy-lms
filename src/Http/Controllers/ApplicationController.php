<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Admissions\DraftApplicationService;
use Academy\Domain\Admissions\ApplicationDraftFactory;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
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
        $application = $this->applications->getOwn($this->auth($request), $applicationId);

        $html = $this->renderer->render('pages/applications/show', [
            'title' => 'My application',
            'application' => $application,
        ]);

        return new HtmlResponse($html);
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
