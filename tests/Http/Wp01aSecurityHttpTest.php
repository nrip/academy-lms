<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use League\Route\Router;
use PHPUnit\Framework\TestCase;

final class Wp01aSecurityHttpTest extends TestCase
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
        DatabaseTestCase::truncateWp01aTables();

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testObservedMiddlewareOrder(): void
    {
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-WP01A-Trace', '1');

        $response = ApplicationFactory::handle($request);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'TrustedProxy',
                'RequestId',
                'ExceptionHandler',
                'SecurityHeaders',
                'Session',
                'Authentication',
                'Csrf',
                'RateLimit',
            ],
            $payload['observed_order'],
        );
    }

    public function testValidCsrfHeaderTokenAllowsPost(): void
    {
        $boot = $this->bootSession();
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'POST'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $boot['csrf'])
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);

        $response = ApplicationFactory::handle($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testValidCsrfBodyTokenAllowsPost(): void
    {
        $boot = $this->bootSession();
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'POST'))
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['_csrf' => $boot['csrf']])
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);

        $response = ApplicationFactory::handle($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testValidSessionAndCsrfCookiesWithoutSubmittedTokenReturns403(): void
    {
        $boot = $this->bootSession();
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'POST'))
            ->withHeader('Accept', 'application/json')
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);

        $response = ApplicationFactory::handle($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CSRF_FAILED', $payload['error']['code']);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testInvalidCsrfHeaderTokenReturns403(): void
    {
        $boot = $this->bootSession();
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'POST'))
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', 'not-the-real-token')
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);

        $response = ApplicationFactory::handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvalidCsrfBodyTokenReturns403(): void
    {
        $boot = $this->bootSession();
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'POST'))
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['_csrf' => 'not-the-real-token'])
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);

        $response = ApplicationFactory::handle($request);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testRateLimitReturns429WithRetryAfter(): void
    {
        $boot = $this->bootSession();
        $cookies = [
            $this->sessionCookieName => $boot['session'],
            $this->csrfCookieName => $boot['csrf'],
        ];

        $last = null;
        for ($i = 0; $i < 4; ++$i) {
            $request = new ServerRequest(
                ['REMOTE_ADDR' => '203.0.113.10'],
                [],
                'http://localhost/__wp01a/limited',
                'POST',
            );
            $request = $request
                ->withHeader('Accept', 'application/json')
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withCookieParams($cookies);
            $last = ApplicationFactory::handle($request);
        }

        self::assertNotNull($last);
        self::assertSame(429, $last->getStatusCode());
        self::assertNotSame('', $last->getHeaderLine('Retry-After'));
    }

    public function testProbeRoutesReturn404InProduction(): void
    {
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
            ->withHeader('Accept', 'application/json');

        $response = ApplicationFactory::handle($request, 'production');
        self::assertSame(404, $response->getStatusCode());
    }

    public function testProbeRoutesReturn404InStaging(): void
    {
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
            ->withHeader('Accept', 'application/json');

        $response = ApplicationFactory::handle($request, 'staging');
        self::assertSame(404, $response->getStatusCode());
    }

    public function testProbeRoutesNotRegisteredOutsideTesting(): void
    {
        $container = ApplicationFactory::container('production');
        $router = $container->get(Router::class);

        foreach ($router->getRoutes() as $route) {
            self::assertStringNotContainsString(
                '/__wp01a/',
                $route->getPath(),
                'Probe routes must not be registered outside testing.',
            );
        }

        $dispatch = static function (string $method, string $path) use ($router): int {
            $request = (new ServerRequest([], [], 'http://localhost' . $path, $method))
                ->withHeader('Accept', 'application/json');

            try {
                $response = $router->dispatch($request);

                return $response->getStatusCode();
            } catch (\League\Route\Http\Exception\NotFoundException) {
                return 404;
            }
        };

        self::assertSame(404, $dispatch('GET', '/__wp01a/probe'));
        self::assertSame(404, $dispatch('POST', '/__wp01a/probe'));
        self::assertSame(404, $dispatch('GET', '/__wp01a/protected'));
        self::assertSame(404, $dispatch('POST', '/__wp01a/limited'));
        self::assertSame(200, $dispatch('GET', '/health'));
    }

    public function testHealthStillWorks(): void
    {
        $request = (new ServerRequest([], [], 'http://localhost/health', 'GET'))
            ->withHeader('Accept', 'application/json');
        $response = ApplicationFactory::handle($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testProductionUsesHostPrefixedSecureCookies(): void
    {
        $session = ApplicationFactory::securityConfig('production')['session'];
        self::assertSame('__Host-acad_session', $session['cookies']['session_name']);
        self::assertSame('__Host-acad_csrf', $session['cookies']['csrf_name']);
        self::assertTrue($session['cookie_secure']);
    }

    public function testTestingUsesLocalCookieNamesOverHttp(): void
    {
        $session = ApplicationFactory::securityConfig('testing')['session'];
        self::assertSame('acad_session', $session['cookies']['session_name']);
        self::assertSame('acad_csrf', $session['cookies']['csrf_name']);
        self::assertFalse($session['cookie_secure']);
    }

    /**
     * @return array{session: string, csrf: string}
     */
    private function bootSession(): array
    {
        $request = (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
            ->withHeader('Accept', 'application/json');
        $response = ApplicationFactory::handle($request);
        self::assertSame(200, $response->getStatusCode());

        $session = null;
        $csrf = null;
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (str_starts_with($cookie, $this->sessionCookieName . '=')) {
                $session = rawurldecode(explode(';', substr($cookie, strlen($this->sessionCookieName) + 1))[0]);
            }
            if (str_starts_with($cookie, $this->csrfCookieName . '=')) {
                $csrf = rawurldecode(explode(';', substr($cookie, strlen($this->csrfCookieName) + 1))[0]);
            }
        }
        self::assertNotNull($session);
        self::assertNotNull($csrf);

        return ['session' => $session, 'csrf' => $csrf];
    }
}
