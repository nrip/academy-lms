<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\RequirePermissionMiddleware;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Binds a real, DB-backed pending_verification session and drives the production
 * RequirePermissionMiddleware + AuthorizationService (with the real PdoPermissionRepository)
 * gate. No route is yet bound to 'application.create' or 'identity.verification.view_own',
 * so those two specific permissions are proven through the same production components the
 * router would attach via RouteAccess, rather than through an as-yet-nonexistent URL.
 * The finance-permission probe route (/admin/__wp01b/rbac/refund) is exercised end-to-end
 * over real HTTP for a full-stack smoke check.
 */
final class PendingAuthorizationHttpTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;

    protected function setUp(): void
    {
        if (!DatabaseTestCase::available()) {
            self::markTestSkipped('MySQL is not available.');
        }
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('RATE_LIMIT_PEPPER=test-pepper');
        $_ENV['RATE_LIMIT_PEPPER'] = 'test-pepper';
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testPendingApplicantDeniedFinancePermissionOverRealHttp(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'pendinghttp.finance.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::PENDING_VERIFICATION,
        );
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/refund', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPendingApplicantDeniedApplicationCreateEvenThoughRoleGrantsIt(): void
    {
        $context = $this->pendingApplicantContext();

        $this->expectException(AuthorizationException::class);
        $this->runPermissionGate($context, 'application.create');
    }

    public function testPendingApplicantAllowedIdentityVerificationViewOwn(): void
    {
        $context = $this->pendingApplicantContext();

        $response = $this->runPermissionGate($context, 'identity.verification.view_own');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testActiveApplicantIsAllowedApplicationCreate(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'pendinghttp.active.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::ACTIVE,
        );
        $context = AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::ACTIVE,
        );

        $response = $this->runPermissionGate($context, 'application.create');
        self::assertSame(200, $response->getStatusCode());
    }

    private function pendingApplicantContext(): AuthContext
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'pendinghttp.gate.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::PENDING_VERIFICATION,
        );

        return AuthContext::authenticated(
            userId: $user['user_id'],
            sessionId: 1,
            authStage: AuthStage::FULLY_AUTHENTICATED,
            authVersion: $user['auth_version'],
            hasPrivilegedRole: false,
            accountStatus: AccountStatus::PENDING_VERIFICATION,
        );
    }

    private function runPermissionGate(AuthContext $context, string $permissionKey): ResponseInterface
    {
        $container = ApplicationFactory::container('testing');
        /** @var AuthorizationService $authorization */
        $authorization = $container->get(AuthorizationService::class);
        $middleware = new RequirePermissionMiddleware($authorization, $permissionKey);

        $request = (new ServerRequest([], [], 'http://localhost/__pending-auth-gate', 'GET'))
            ->withAttribute(AuthenticationMiddleware::ATTR_AUTH, $context);

        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['ok' => true]);
            }
        };

        return $middleware->process($request, $handler);
    }
}
