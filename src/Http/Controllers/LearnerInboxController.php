<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Notifications\LearnerInboxQueryService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LearnerInboxController
{
    public function __construct(
        private readonly LearnerInboxQueryService $inbox,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->inbox->listOwn($this->auth($request));
        $html = $this->renderer->render('pages/notifications/index', [
            'title' => 'Updates',
            'csrf' => $this->csrf($request),
            'items' => $page['items'],
            'unread' => $page['unread'],
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function markRead(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $id = (int) ($args['notificationId'] ?? 0);
        $this->inbox->markRead($this->auth($request), $id);

        return new RedirectResponse('/notifications', 303);
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
