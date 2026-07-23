<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\LoginService;
use Academy\Application\Identity\LogoutService;
use Academy\Application\Identity\PostLoginDestinationResolver;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Identity\UserSecuritySnapshotRepository;
use Academy\Domain\Security\AuthContext;
use Academy\Domain\Security\SessionRecord;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Security\SessionCookieClearance;
use Academy\Http\Security\SessionCookieSettings;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LoginController
{
    public function __construct(
        private readonly LoginService $login,
        private readonly LogoutService $logout,
        private readonly SessionService $sessions,
        private readonly SessionCookieSettings $sessionCookies,
        private readonly PhpRenderer $renderer,
        private readonly PostLoginDestinationResolver $destinations,
        private readonly UserSecuritySnapshotRepository $securitySnapshots,
    ) {
    }

    public function showForm(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AuthContext|null $auth */
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if ($auth instanceof AuthContext && $auth->authenticated) {
            return new RedirectResponse(
                $this->destinations->resolve($auth, $this->extractReturnTo($request)),
                302,
            );
        }

        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
        $returnTo = $this->extractReturnTo($request);

        $html = $this->renderer->render('pages/auth/login', [
            'title' => 'Sign in',
            'csrf' => $csrf,
            'error' => null,
            'return_to' => $returnTo,
        ]);

        return new HtmlResponse($html);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $returnTo = $this->extractReturnTo($request);

        /** @var string $csrf */
        $csrf = (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');

        try {
            $success = $this->login->authenticate($email, $password, $this->clientIp($request));
        } catch (AuthenticationException) {
            $html = $this->renderer->render('pages/auth/login', [
                'title' => 'Sign in',
                'csrf' => $csrf,
                'error' => LoginService::GENERIC_FAILURE,
                'return_to' => $returnTo,
            ]);

            return new HtmlResponse($html, 401);
        }

        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        if (!$session instanceof SessionRecord) {
            $html = $this->renderer->render('pages/auth/login', [
                'title' => 'Sign in',
                'csrf' => $csrf,
                'error' => LoginService::GENERIC_FAILURE,
                'return_to' => $returnTo,
            ]);

            return new HtmlResponse($html, 401);
        }

        $established = $this->login->establishSession($this->sessions, $session, $success);
        $destination = $this->resolveAfterLogin($success->userId, $established['record'], $returnTo);

        $response = new RedirectResponse($destination, 302);
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->sessionCookies->buildSessionSetCookie($established['raw_token']),
        );
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->sessionCookies->buildCsrfSetCookie($established['raw_csrf']),
        );

        return $response;
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionRecord|null $session */
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        /** @var AuthContext|null $auth */
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        $userId = $auth instanceof AuthContext && $auth->authenticated ? $auth->userId : null;
        /** @var SessionCookieClearance|null $clearance */
        $clearance = $request->getAttribute(SessionCookieClearance::ATTR);

        $this->logout->logout(
            $session instanceof SessionRecord ? $session : null,
            $userId,
            $clearance instanceof SessionCookieClearance ? $clearance : null,
        );

        $response = new RedirectResponse('/login', 302);
        foreach ($this->sessionCookies->clearCookieHeaders() as $header) {
            $response = $response->withAddedHeader('Set-Cookie', $header);
        }

        return $response;
    }

    private function resolveAfterLogin(int $userId, SessionRecord $session, ?string $returnTo): string
    {
        $snapshot = $this->securitySnapshots->findById($userId);
        if ($snapshot === null) {
            return PostLoginDestinationResolver::COURSES;
        }

        $rawStage = null;
        if (isset($session->payload['auth_stage']) && is_string($session->payload['auth_stage'])) {
            $rawStage = $session->payload['auth_stage'];
        }
        $authStage = AuthStage::resolveEffective($rawStage, $snapshot->hasPrivilegedRole);

        $auth = AuthContext::authenticated(
            userId: $snapshot->userId,
            sessionId: $session->sessionId,
            authStage: $authStage,
            authVersion: $snapshot->authVersion,
            hasPrivilegedRole: $snapshot->hasPrivilegedRole,
            accountStatus: $snapshot->accountStatus,
        );

        return $this->destinations->resolve($auth, $returnTo);
    }

    private function extractReturnTo(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['return_to']) && is_string($body['return_to'])) {
            return $body['return_to'];
        }

        $query = $request->getQueryParams();
        if (isset($query['return_to']) && is_string($query['return_to'])) {
            return $query['return_to'];
        }

        return null;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!is_string($clientIp) || $clientIp === '') {
            return '0.0.0.0';
        }

        return $clientIp;
    }
}
