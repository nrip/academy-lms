<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class PasswordResetHttpTest extends TestCase
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

    public function testForgotPasswordGenericResponse(): void
    {
        $boot = $this->bootSession();
        $email = 'httpforgot.' . bin2hex(random_bytes(3)) . '@example.test';
        DatabaseTestCase::createSyntheticUser($email, '9' . random_int(100000000, 999999999));

        $existing = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.50'], [], 'http://localhost/forgot-password', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['_csrf' => $boot['csrf'], 'email' => $email])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        $missing = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.51'], [], 'http://localhost/forgot-password', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['_csrf' => $boot['csrf'], 'email' => 'missing.' . bin2hex(random_bytes(3)) . '@example.test'])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(302, $existing->getStatusCode());
        self::assertSame(302, $missing->getStatusCode());
        self::assertSame('/forgot-password/sent', $existing->getHeaderLine('Location'));
        self::assertSame($existing->getHeaderLine('Location'), $missing->getHeaderLine('Location'));
    }

    public function testScannerSafeResetFlowCompletesWithoutAutoLogin(): void
    {
        $email = 'httpreset.' . bin2hex(random_bytes(3)) . '@example.test';
        $user = DatabaseTestCase::createSyntheticUser($email, '9' . random_int(100000000, 999999999));
        $boot = $this->bootSession();
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $issued = $issuer->issue(
            $user['user_id'],
            TokenPurpose::PASSWORD_RESET,
            $email,
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour'),
        );

        $get = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.52'], [], 'http://localhost/reset-password', 'GET'))
                ->withQueryParams(['token' => $issued['raw_token']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(302, $get->getStatusCode());
        self::assertSame('/reset-password/confirm', $get->getHeaderLine('Location'));
        self::assertStringNotContainsString('token=', $get->getHeaderLine('Location'));
        $confirmSecret = $this->cookieValue($get->getHeader('Set-Cookie'), 'acad_reset_confirm');
        self::assertNotNull($confirmSecret);

        $confirm = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/reset-password/confirm', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                    'acad_reset_confirm' => $confirmSecret,
                ]),
        );
        self::assertSame(302, $confirm->getStatusCode());
        self::assertSame('/reset-password/form', $confirm->getHeaderLine('Location'));
        $authSecret = $this->cookieValue($confirm->getHeader('Set-Cookie'), 'acad_reset_auth');
        self::assertNotNull($authSecret);

        $complete = ApplicationFactory::handle(
            (new ServerRequest(['REMOTE_ADDR' => '198.51.100.52'], [], 'http://localhost/reset-password', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    '_csrf' => $boot['csrf'],
                    'password' => 'a-brand-new-http-password-1',
                    'password_confirm' => 'a-brand-new-http-password-1',
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                    'acad_reset_auth' => $authSecret,
                ]),
        );
        self::assertSame(302, $complete->getStatusCode());
        self::assertSame('/reset-password/result?status=success', $complete->getHeaderLine('Location'));

        $probe = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        $payload = json_decode((string) $probe->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['authenticated'], 'Password reset must not auto-login');
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
