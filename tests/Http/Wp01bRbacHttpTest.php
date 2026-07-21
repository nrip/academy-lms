<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use League\Route\Router;
use PHPUnit\Framework\TestCase;

final class Wp01bRbacHttpTest extends TestCase
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

    public function testProbeRoutesNotRegisteredOutsideTesting(): void
    {
        $router = ApplicationFactory::container('local')->get(Router::class);
        $found = false;
        foreach ($router->getRoutes() as $route) {
            if (str_contains($route->getPath(), '__wp01b')) {
                $found = true;
                break;
            }
        }
        self::assertFalse($found, 'WP-01B probe routes must not exist outside APP_ENV=testing');

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/allow', 'GET'))
                ->withHeader('Accept', 'application/json'),
            'local',
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testUnauthenticatedPermissionRouteReturns401(): void
    {
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/allow', 'GET'))
                ->withHeader('Accept', 'application/json'),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function testApplicantDeniedDocumentPermissionReturns403(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/document', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testFinanceDeniedDocumentPermissionReturns403(): void
    {
        $user = DatabaseTestCase::financeFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/document', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testReviewerDeniedRefundPermissionReturns403(): void
    {
        $user = DatabaseTestCase::reviewerFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/refund', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSuperAdminWithFullAuthAllowed(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/allow', $boot, true);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('Permission', $payload['observed_order']);
        self::assertSame(
            [
                'TrustedProxy',
                'RequestId',
                'ExceptionHandler',
                'SecurityHeaders',
                'Session',
                'Authentication',
                'RateLimit',
                'Csrf',
                'Permission',
            ],
            $payload['observed_order'],
        );
    }

    public function testBootstrapStylePrivilegedSessionCannotPassPrivilegedProbe(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser(
            $user['user_id'],
            $user['auth_version'],
            AuthStage::MFA_ENROLMENT_REQUIRED,
        );
        $response = $this->get('/admin/__wp01b/rbac/allow', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testPrivilegedMissingAuthStageFailsClosed(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $container = ApplicationFactory::container('testing');
        /** @var \Academy\Application\Security\SessionService $sessions */
        $sessions = $container->get(\Academy\Application\Security\SessionService::class);
        $loaded = $sessions->loadOrCreate(null, '127.0.0.1', 'phpunit');
        $sessions->bindUser($loaded['record'], $user['user_id'], $user['auth_version'], []);
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/__wp01b/rbac/allow', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $loaded['raw_token'],
                    $this->csrfCookieName => $loaded['raw_csrf'],
                ]),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAuthVersionMismatchInvalidatesSession(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE user_id = ?')
            ->execute([$user['user_id']]);

        $response = $this->get('/admin/__wp01b/rbac/allow', $boot);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testAuthVersionMismatchClearsCookiesWithoutReissue(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version']);
        $pdo = DatabaseTestCase::pdo();
        $pdo->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE user_id = ?')
            ->execute([$user['user_id']]);

        $response = $this->get('/admin/__wp01b/rbac/allow', $boot);
        self::assertSame(401, $response->getStatusCode());

        $setCookies = $response->getHeader('Set-Cookie');
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

        self::assertCount(1, $sessionHeaders, 'Session cookie must appear exactly once as a clearing directive.');
        self::assertCount(1, $csrfHeaders, 'CSRF cookie must appear exactly once as a clearing directive.');
        self::assertTrue($this->isExpiredClearingCookie($sessionHeaders[0]), $sessionHeaders[0]);
        self::assertTrue($this->isExpiredClearingCookie($csrfHeaders[0]), $csrfHeaders[0]);
        self::assertFalse($this->isLiveCookieValue($sessionHeaders[0]), 'Session cookie must not be reissued live.');
        self::assertFalse($this->isLiveCookieValue($csrfHeaders[0]), 'CSRF cookie must not be reissued live.');
    }

    public function testHealthyRequestRetainsNormalCookieBehaviourWithoutClearance(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/allow', $boot);
        self::assertSame(200, $response->getStatusCode());

        foreach ($response->getHeader('Set-Cookie') as $header) {
            $name = strtolower(strtok($header, '=') ?: '');
            if ($name === strtolower($this->sessionCookieName) || $name === strtolower($this->csrfCookieName)) {
                self::assertFalse($this->isExpiredClearingCookie($header), $header);
            }
        }
        self::assertFalse(method_exists(\Academy\Application\Security\SessionService::class, 'isActive'));
    }

    public function testSuspendedUserInvalidatesSession(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'susp@example.test',
            '+916166666666',
            [RoleKeys::SUPER_ADMIN],
            AccountStatus::SUSPENDED,
        );
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/allow', $boot);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLockedUntilInvalidatesSession(): void
    {
        $user = DatabaseTestCase::createSyntheticUser(
            'locked@example.test',
            '+916177777777',
            [RoleKeys::SUPER_ADMIN],
            AccountStatus::ACTIVE,
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 hour'),
        );
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/allow', $boot);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testParameterizedRouteUsesConstructorBoundPermission(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/item/42', $boot);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('42', $payload['id']);
    }

    public function testParameterizedRouteDeniesAuthenticatedUserWithoutPermission(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/item/99', $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnknownPermissionKeyFailsClosedWith403(): void
    {
        $user = DatabaseTestCase::superAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->get('/admin/__wp01b/rbac/unknown', $boot);
        self::assertSame(403, $response->getStatusCode());
        self::assertNotSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('FORBIDDEN', $payload['error']['code']);
    }

    public function testKernelStillOrdersRateLimitBeforeCsrf(): void
    {
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-WP01A-Trace', '1');
        $response = ApplicationFactory::handle($request);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $order = $payload['observed_order'];
        self::assertLessThan(
            array_search('Csrf', $order, true),
            array_search('RateLimit', $order, true),
        );
        self::assertNotContains('Permission', $order);
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function get(string $path, array $boot, bool $trace = false): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest([], [], 'http://localhost' . $path, 'GET'))
            ->withHeader('Accept', 'application/json')
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);
        if ($trace) {
            $request = $request->withHeader('X-WP01A-Trace', '1');
        }

        return ApplicationFactory::handle($request);
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
}
