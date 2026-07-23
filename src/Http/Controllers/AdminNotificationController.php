<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Notifications\AdminNotificationQueryService;
use Academy\Application\Notifications\AdminNotificationRetryService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminNotificationController
{
    public function __construct(
        private readonly AdminNotificationQueryService $query,
        private readonly AdminNotificationRetryService $retry,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $status = isset($params['status']) && is_string($params['status']) && $params['status'] !== ''
            ? $params['status']
            : null;
        $page = $this->query->list($this->auth($request), $status);

        $html = $this->renderer->render('pages/admin/notifications/index', [
            'title' => 'Notification deliveries',
            'csrf' => $this->csrf($request),
            'page' => $page,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $delivery = $this->query->detail($this->auth($request), $id);

        $html = $this->renderer->render('pages/admin/notifications/show', [
            'title' => 'Notification delivery',
            'csrf' => $this->csrf($request),
            'delivery' => $delivery,
            'error' => null,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function retry(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->retry->retry($this->auth($request), $id);
        } catch (ConflictException $exception) {
            $delivery = $this->query->detail($this->auth($request), $id);
            $html = $this->renderer->render('pages/admin/notifications/show', [
                'title' => 'Notification delivery',
                'csrf' => $this->csrf($request),
                'delivery' => $delivery,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, 409);
        }

        return new RedirectResponse('/admin/notifications/' . $id, 303);
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
