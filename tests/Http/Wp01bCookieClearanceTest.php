<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\SessionService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\Security\SessionRecord;
use Academy\Domain\Security\SessionRepository;
use Academy\Http\Kernel;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\ExceptionHandlerMiddleware;
use Academy\Http\Middleware\RequirePermissionMiddleware;
use Academy\Http\Middleware\SecurityHeadersMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Routing\RouteRequestHandler;
use Academy\Http\Security\SecurityHeaderPolicy;
use Academy\Http\Security\SessionCookieClearance;
use Academy\Http\Security\SessionCookieSettings;
use Academy\Infrastructure\Identity\PdoUserSecuritySnapshotRepository;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Infrastructure\View\Escaper;
use Academy\Infrastructure\View\PhpRenderer;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class Wp01bCookieClearanceTest extends TestCase
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
        DatabaseTestCase::migrate();
        DatabaseTestCase::truncateAllTestTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testAuthVersionMismatchClearsCookiesThroughFullPipeline(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        DatabaseTestCase::pdo()
            ->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE user_id = ?')
            ->execute([$user['user_id']]);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/allow', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(401, $response->getStatusCode());
        $this->assertClearOnlyManagedCookies($response->getHeader('Set-Cookie'));
    }

    public function testRevokeFailureStillClearsCookiesAndReturns401(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        DatabaseTestCase::pdo()
            ->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE user_id = ?')
            ->execute([$user['user_id']]);

        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $cookieSettings = new SessionCookieSettings($this->sessionCookieName, $this->csrfCookieName, false);
        $innerRepo = new PdoSessionRepository(DatabaseTestCase::connectionFactory());
        $repo = new class ($innerRepo) implements SessionRepository {
            public function __construct(private readonly SessionRepository $inner)
            {
            }

            public function findByTokenHash(string $tokenHash): ?SessionRecord
            {
                return $this->inner->findByTokenHash($tokenHash);
            }

            public function create(string $tokenHash, ?string $csrfTokenHash, array $payload, \DateTimeImmutable $createdAt, \DateTimeImmutable $absoluteExpiresAt, \DateTimeImmutable $idleExpiresAt, ?string $ipAddress, ?string $userAgentHash): SessionRecord
            {
                return $this->inner->create($tokenHash, $csrfTokenHash, $payload, $createdAt, $absoluteExpiresAt, $idleExpiresAt, $ipAddress, $userAgentHash);
            }

            public function regenerate(int $sessionId, string $newTokenHash, ?string $newCsrfTokenHash, \DateTimeImmutable $now, \DateTimeImmutable $absoluteExpiresAt, \DateTimeImmutable $idleExpiresAt): void
            {
                $this->inner->regenerate($sessionId, $newTokenHash, $newCsrfTokenHash, $now, $absoluteExpiresAt, $idleExpiresAt);
            }

            public function updateCsrfHash(int $sessionId, string $csrfTokenHash): void
            {
                $this->inner->updateCsrfHash($sessionId, $csrfTokenHash);
            }

            public function touch(int $sessionId, \DateTimeImmutable $lastActivityAt, \DateTimeImmutable $idleExpiresAt): void
            {
                $this->inner->touch($sessionId, $lastActivityAt, $idleExpiresAt);
            }

            public function revoke(int $sessionId, \DateTimeImmutable $revokedAt): void
            {
                throw new \RuntimeException('forced revoke failure');
            }

            public function bindUser(int $sessionId, int $userId, int $authVersion, array $payloadMerge = []): void
            {
                $this->inner->bindUser($sessionId, $userId, $authVersion, $payloadMerge);
            }

            public function mergeAnonymousPayload(int $sessionId, array $payloadMerge): void
            {
                $this->inner->mergeAnonymousPayload($sessionId, $payloadMerge);
            }

            public function revokeAllForUser(int $userId, \DateTimeImmutable $revokedAt): int
            {
                return $this->inner->revokeAllForUser($userId, $revokedAt);
            }

            public function deleteExpired(\DateTimeImmutable $now, int $limit = 1000): int
            {
                return $this->inner->deleteExpired($now, $limit);
            }
        };

        $sessions = new SessionService(
            $repo,
            new CsrfTokenManager(),
            $logger,
            ['idle_seconds' => 1800, 'absolute_seconds' => 43200],
            ['idle_seconds' => 900, 'absolute_seconds' => 28800],
            300,
        );

        $permissions = new class () implements PermissionRepository {
            public function permissionKeysForUser(int $userId): array
            {
                return ['rbac.role.view'];
            }

            public function permissionKeysForRoleKey(string $roleKey): array
            {
                return [];
            }
        };

        $router = new Router();
        $router->setStrategy(new ApplicationStrategy());
        $route = $router->get('/admin/__wp01b/rbac/allow', static fn () => new \Laminas\Diactoros\Response\JsonResponse(['ok' => true]));
        $route->middleware(new RequirePermissionMiddleware(new AuthorizationService($permissions), 'rbac.role.view'));

        $kernel = new Kernel(
            [
                new ExceptionHandlerMiddleware(
                    $logger,
                    new PhpRenderer(dirname(__DIR__, 2) . '/templates', new Escaper()),
                    new SecurityHeaderPolicy(false),
                    $cookieSettings,
                    true,
                    'testing',
                ),
                new SecurityHeadersMiddleware(new SecurityHeaderPolicy(false)),
                new SessionMiddleware($sessions, $cookieSettings, []),
                new AuthenticationMiddleware(
                    new PdoUserSecuritySnapshotRepository(DatabaseTestCase::connectionFactory()),
                    $sessions,
                    $cookieSettings,
                ),
            ],
            new RouteRequestHandler($router),
        );

        $response = $kernel->handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/allow', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(401, $response->getStatusCode());
        $this->assertClearOnlyManagedCookies($response->getHeader('Set-Cookie'));

        $joined = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($boot['session'], $joined);
        self::assertStringNotContainsString($boot['csrf'], $joined);
        self::assertStringNotContainsString('session_token', strtolower($joined));
        self::assertTrue(
            $this->loggerSawRevokeFailure($logger),
            'Expected a revoke-failure log without sensitive payloads.',
        );
    }

    public function testHealthyAuthenticatedRequestDoesNotEmitClearanceCookies(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/allow', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(200, $response->getStatusCode());
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $name = strtolower(strtok($header, '=') ?: '');
            if ($name === strtolower($this->sessionCookieName) || $name === strtolower($this->csrfCookieName)) {
                self::assertFalse(
                    $this->isExpiredClearingCookie($header),
                    'Healthy requests must not emit clearance cookies: ' . $header,
                );
            }
        }
        self::assertFalse(
            method_exists(SessionService::class, 'isActive'),
            'SessionService::isActive must remain removed.',
        );
    }

    public function testExceptionHandlerHonoursSharedClearanceFlag(): void
    {
        $cookieSettings = new SessionCookieSettings($this->sessionCookieName, $this->csrfCookieName, false);
        $middleware = new ExceptionHandlerMiddleware(
            new NullLogger(),
            new PhpRenderer(dirname(__DIR__, 2) . '/templates', new Escaper()),
            new SecurityHeaderPolicy(false),
            $cookieSettings,
            true,
            'testing',
        );

        $response = $middleware->process(
            (new ServerRequest([], [], 'http://localhost/admin/probe', 'GET'))
                ->withHeader('Accept', 'application/json'),
            new class () implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $clearance = $request->getAttribute(SessionCookieClearance::ATTR);
                    Assert::assertInstanceOf(SessionCookieClearance::class, $clearance);
                    $clearance->requestClear();
                    throw new AuthenticationException('Authentication required.');
                }
            },
        );

        self::assertSame(401, $response->getStatusCode());
        $this->assertClearOnlyManagedCookies($response->getHeader('Set-Cookie'));
    }

    /**
     * @param list<string> $setCookies
     */
    private function assertClearOnlyManagedCookies(array $setCookies): void
    {
        $sessionHeaders = [];
        $csrfHeaders = [];
        foreach ($setCookies as $header) {
            $name = strtolower(strtok($header, '=') ?: '');
            if ($name === strtolower($this->sessionCookieName)) {
                $sessionHeaders[] = $header;
            }
            if ($name === strtolower($this->csrfCookieName)) {
                $csrfHeaders[] = $header;
            }
        }

        self::assertCount(1, $sessionHeaders, 'Session cookie must appear exactly once.');
        self::assertCount(1, $csrfHeaders, 'CSRF cookie must appear exactly once.');
        self::assertTrue($this->isExpiredClearingCookie($sessionHeaders[0]), $sessionHeaders[0]);
        self::assertTrue($this->isExpiredClearingCookie($csrfHeaders[0]), $csrfHeaders[0]);
        self::assertFalse($this->isLiveCookieValue($sessionHeaders[0]));
        self::assertFalse($this->isLiveCookieValue($csrfHeaders[0]));
    }

    private function isExpiredClearingCookie(string $header): bool
    {
        $lower = strtolower($header);

        return str_contains($lower, 'max-age=0')
            || str_contains($lower, 'expires=thu, 01 jan 1970');
    }

    private function isLiveCookieValue(string $header): bool
    {
        $parts = explode(';', $header, 2);
        $pair = $parts[0];
        $eq = strpos($pair, '=');
        if ($eq === false) {
            return false;
        }
        $value = substr($pair, $eq + 1);

        return $value !== '' && !str_contains(strtolower($header), 'max-age=0');
    }

    /**
     * @param object{records: list<array{level: string, message: string, context: array<string, mixed>}>} $logger
     */
    private function loggerSawRevokeFailure(object $logger): bool
    {
        foreach ($logger->records as $record) {
            if (str_contains(strtolower($record['message']), 'revoke')) {
                return true;
            }
            if (($record['context']['exception'] ?? null) === \RuntimeException::class) {
                return true;
            }
        }

        return false;
    }
}
