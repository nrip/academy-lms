<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Identity\LoginService;
use Academy\Application\Identity\PasswordHasher;
use Academy\Domain\Identity\AccountStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class LoginHttpTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;
    private string $password = 'a-strong-http-login-password-1';

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

    public function testLoginGetReturnsForm(): void
    {
        $response = ApplicationFactory::handle(new ServerRequest([], [], 'http://localhost/login', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="email"', (string) $response->getBody());
        self::assertStringContainsString('name="password"', (string) $response->getBody());
    }

    public function testLoginPostWithoutCsrfReturns403(): void
    {
        $boot = $this->bootSession();
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/login', 'POST'))
                ->withParsedBody(['email' => 'a@example.test', 'password' => 'x'])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSuccessfulLoginRedirectsAndRotatesSession(): void
    {
        $email = 'httplogin.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createActiveUser($email, $this->password);
        $boot = $this->bootSession();
        $oldSession = $boot['session'];

        $response = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.40'], [], 'http://localhost/login', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    '_csrf' => $boot['csrf'],
                    'email' => $email,
                    'password' => $this->password,
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/smoke', $response->getHeaderLine('Location'));
        $newSession = $this->cookieValue($response->getHeader('Set-Cookie'), $this->sessionCookieName);
        self::assertNotNull($newSession);
        self::assertNotSame($oldSession, $newSession);

        $probe = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $newSession,
                    $this->csrfCookieName => $this->cookieValue($response->getHeader('Set-Cookie'), $this->csrfCookieName) ?? $boot['csrf'],
                ]),
        );
        $payload = json_decode((string) $probe->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['authenticated']);
    }

    public function testFailedLoginReturnsGenericMessage(): void
    {
        $email = 'httpfail.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createActiveUser($email, $this->password);
        $boot = $this->bootSession();

        $response = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.41'], [], 'http://localhost/login', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    '_csrf' => $boot['csrf'],
                    'email' => $email,
                    'password' => 'wrong-password-xxxxx',
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(401, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString(LoginService::GENERIC_FAILURE, $body);
        self::assertStringNotContainsString('locked', strtolower($body));
        self::assertStringNotContainsString('suspended', strtolower($body));
        self::assertStringNotContainsString($email, $body);
    }

    public function testLogoutPostOnlyAndIsIdempotent(): void
    {
        $email = 'httplogout.' . bin2hex(random_bytes(3)) . '@example.test';
        $this->createActiveUser($email, $this->password);
        $boot = $this->bootSession();

        $login = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.42'], [], 'http://localhost/login', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    '_csrf' => $boot['csrf'],
                    'email' => $email,
                    'password' => $this->password,
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(302, $login->getStatusCode());
        $session = $this->cookieValue($login->getHeader('Set-Cookie'), $this->sessionCookieName);
        $csrf = $this->cookieValue($login->getHeader('Set-Cookie'), $this->csrfCookieName);
        self::assertNotNull($session);
        self::assertNotNull($csrf);

        $get = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/logout', 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $session,
                    $this->csrfCookieName => $csrf,
                ]),
        );
        self::assertContains($get->getStatusCode(), [404, 405]);

        $post = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/logout', 'POST'))
                ->withHeader('X-CSRF-Token', $csrf)
                ->withParsedBody(['_csrf' => $csrf])
                ->withCookieParams([
                    $this->sessionCookieName => $session,
                    $this->csrfCookieName => $csrf,
                ]),
        );
        self::assertSame(302, $post->getStatusCode());
        self::assertSame('/login', $post->getHeaderLine('Location'));

        $again = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/logout', 'POST'))
                ->withHeader('X-CSRF-Token', $csrf)
                ->withParsedBody(['_csrf' => $csrf])
                ->withCookieParams([
                    $this->sessionCookieName => $session,
                    $this->csrfCookieName => $csrf,
                ]),
        );
        // After revoke, CSRF/session may fail closed; still must not 500.
        self::assertContains($again->getStatusCode(), [302, 403]);
    }

    /**
     * @return array{user_id: int, auth_version: int}
     */
    private function createActiveUser(string $email, string $password): array
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = (new PasswordHasher())->hash($password);
        $mobile = '9' . random_int(100000000, 999999999);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, NULL, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            strtolower($email), $now, $mobile, $now, $hash, AccountStatus::ACTIVE,
            $now, $now, 'terms.v1', $now, 'privacy.v1', 'Asia/Kolkata', $now, $now,
        ]);

        return ['user_id' => (int) $pdo->lastInsertId(), 'auth_version' => 1];
    }

    /**
     * @return array{session: string, csrf: string}
     */
    private function bootSession(): array
    {
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
                ->withHeader('Accept', 'application/json'),
        );
        self::assertSame(200, $response->getStatusCode());
        $session = $this->cookieValue($response->getHeader('Set-Cookie'), $this->sessionCookieName);
        $csrf = $this->cookieValue($response->getHeader('Set-Cookie'), $this->csrfCookieName);
        self::assertNotNull($session);
        self::assertNotNull($csrf);

        return ['session' => $session, 'csrf' => $csrf];
    }

    /**
     * @param list<string> $headers
     */
    private function cookieValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (!str_starts_with($header, $name . '=')) {
                continue;
            }
            $pair = explode(';', $header, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq === false) {
                return null;
            }
            $value = substr($pair, $eq + 1);
            if ($value === '' || str_contains(strtolower($header), 'max-age=0')) {
                return null;
            }

            return rawurldecode($value);
        }

        return null;
    }
}
