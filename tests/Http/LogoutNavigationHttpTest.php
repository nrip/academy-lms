<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Logout must be a POST form with a usable CSRF token on every authenticated page.
 */
final class LogoutNavigationHttpTest extends TestCase
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

    /**
     * @return list<array{0: string, 1: string}> persona fixture method, landing path
     */
    public static function personaProvider(): array
    {
        return [
            'learner courses' => ['applicantFixture', '/courses'],
            'learner profile' => ['applicantFixture', '/profile'],
            'learner dashboard' => ['applicantFixture', '/dashboard'],
            'reviewer queue' => ['reviewerFixture', '/reviewer/applications'],
            'finance payments' => ['financeFixture', '/finance/payments'],
            'super admin notifications' => ['superAdminFixture', '/admin/notifications'],
        ];
    }

    #[DataProvider('personaProvider')]
    public function testRenderedLogoutControlIsPostFormWithCsrfToken(string $fixture, string $path): void
    {
        $boot = $this->bootPersona($fixture);
        $html = (string) $this->get($path, $boot)->getBody();

        self::assertStringContainsString('action="/logout"', $html);
        self::assertStringNotContainsString('href="/logout"', $html);
        self::assertMatchesRegularExpression(
            '#<form method="post" action="/logout"[^>]*>\s*<input type="hidden" name="_csrf" value="[0-9a-f]{16,}">#',
            $html,
            'Logout form must carry a non-empty CSRF token on ' . $path,
        );
    }

    #[DataProvider('personaProvider')]
    public function testLogoutSucceedsUsingTokenRenderedInNavigation(string $fixture, string $path): void
    {
        $boot = $this->bootPersona($fixture);
        $token = $this->logoutFormToken((string) $this->get($path, $boot)->getBody());
        self::assertNotNull($token, 'No CSRF token rendered in logout form on ' . $path);

        $logout = $this->postLogout($boot, $token);
        self::assertSame(302, $logout->getStatusCode());
        self::assertSame('/login', $logout->getHeaderLine('Location'));
    }

    public function testGetLogoutIsNotRoutedAsLogoutAction(): void
    {
        $boot = $this->bootPersona('applicantFixture');

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/logout', 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertContains($response->getStatusCode(), [404, 405]);

        // Session must survive a GET attempt.
        self::assertSame(200, $this->get('/dashboard', $boot)->getStatusCode());
    }

    public function testPostLogoutWithoutCsrfReturns403(): void
    {
        $boot = $this->bootPersona('applicantFixture');

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/logout', 'POST'))
                ->withParsedBody([])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(200, $this->get('/dashboard', $boot)->getStatusCode());
    }

    public function testLogoutClearsCookiesAndProtectedRouteIsDeniedAfterwards(): void
    {
        $boot = $this->bootPersona('applicantFixture');
        self::assertSame(200, $this->get('/dashboard', $boot)->getStatusCode());

        $logout = $this->postLogout($boot, $boot['csrf']);
        self::assertSame(302, $logout->getStatusCode());

        $setCookies = $logout->getHeader('Set-Cookie');
        self::assertNotSame([], $setCookies);
        foreach ([$this->sessionCookieName, $this->csrfCookieName] as $cookieName) {
            $cleared = array_filter(
                $setCookies,
                static fn (string $header): bool => str_starts_with($header, $cookieName . '=')
                    && str_contains(strtolower($header), 'max-age=0'),
            );
            self::assertNotSame([], $cleared, $cookieName . ' must be cleared on logout');
        }

        $after = $this->get('/dashboard', $boot);
        self::assertContains($after->getStatusCode(), [302, 401, 403]);
        self::assertStringNotContainsString('My Applications', (string) $after->getBody());
    }

    public function testAuthenticatedPagesAndLogoutRedirectAreNotHistoryCacheable(): void
    {
        $boot = $this->bootPersona('applicantFixture');

        $dashboard = $this->get('/dashboard', $boot);
        self::assertStringContainsString('no-store', $dashboard->getHeaderLine('Cache-Control'));

        $logout = $this->postLogout($boot, $boot['csrf']);
        self::assertStringContainsString('no-store', $logout->getHeaderLine('Cache-Control'));
    }

    public function testNoTemplateRendersLogoutAsAnchor(): void
    {
        $root = dirname(__DIR__, 2);
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates'),
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'href="/logout"')) {
                $matches[] = $file->getPathname();
            }
        }

        self::assertSame([], $matches, 'Logout must never be rendered as a GET anchor.');
    }

    /**
     * @return array{session: string, csrf: string, user_id: int}
     */
    private function bootPersona(string $fixture): array
    {
        /** @var array{user_id: int, auth_version: int} $user */
        $user = DatabaseTestCase::{$fixture}();
        $boot = DatabaseTestCase::bindSessionForUser(
            $user['user_id'],
            $user['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        return [
            'session' => $boot['session'],
            'csrf' => $boot['csrf'],
            'user_id' => $user['user_id'],
        ];
    }

    private function logoutFormToken(string $html): ?string
    {
        $pattern = '#<form method="post" action="/logout"[^>]*>\s*'
            . '<input type="hidden" name="_csrf" value="([^"]*)">#';
        if (preg_match($pattern, $html, $matches) !== 1) {
            return null;
        }

        return $matches[1] === '' ? null : $matches[1];
    }

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     */
    private function postLogout(array $boot, string $token): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/logout', 'POST'))
                ->withParsedBody(['_csrf' => $token])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     */
    private function get(string $path, array $boot): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
