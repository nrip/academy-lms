<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class Wp01b2aTokenProbeHttpTest extends TestCase
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

    public function testValidGetRedirectsWithoutTokenAndSetsConfirmCookie(): void
    {
        $issued = $this->issueToken(TokenPurpose::EMAIL_VERIFY);
        $response = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.10'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => $issued['raw_token']]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-email/confirm', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('token=', $response->getHeaderLine('Location'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));

        $confirm = $this->cookieValue($response->getHeader('Set-Cookie'), 'acad_email_confirm');
        self::assertNotNull($confirm);
        $setCookie = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('acad_email_confirm=', $setCookie);
    }

    public function testInvalidGetRedirectsToResult(): void
    {
        $response = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.11'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => str_repeat('a', 64)]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-email/result?status=invalid_or_expired', $response->getHeaderLine('Location'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testConfirmPostWithoutCsrfReturns403(): void
    {
        $boot = $this->bootSession();
        $issued = $this->issueToken(TokenPurpose::EMAIL_VERIFY, $boot);
        $get = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.12'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => $issued['raw_token']])
                ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]),
        );
        $secret = $this->cookieValue($get->getHeader('Set-Cookie'), 'acad_email_confirm');
        self::assertNotNull($secret);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-email/confirm', 'POST'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                    'acad_email_confirm' => $secret,
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testConfirmPostWithCsrfAndCookieSucceedsAndClearsCookie(): void
    {
        $boot = $this->bootSession();
        $issued = $this->issueToken(TokenPurpose::EMAIL_VERIFY, $boot);
        $get = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.13'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => $issued['raw_token']])
                ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]),
        );
        $secret = $this->cookieValue($get->getHeader('Set-Cookie'), 'acad_email_confirm');
        self::assertNotNull($secret);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-email/confirm', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                    'acad_email_confirm' => $secret,
                ]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-email/result?status=success', $response->getHeaderLine('Location'));
        $clear = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('acad_email_confirm=', $clear);
        self::assertTrue(
            str_contains(strtolower($clear), 'max-age=0')
            || str_contains(strtolower($clear), 'expires=thu, 01 jan 1970'),
        );

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT consumed_at FROM verification_tokens WHERE verification_token_id = ?');
        $stmt->execute([$issued['verification_token_id']]);
        self::assertNotNull($stmt->fetchColumn());
    }

    public function testPasswordResetUsesSeparateCookieName(): void
    {
        $issued = $this->issueToken(TokenPurpose::PASSWORD_RESET);
        $response = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.14'],
                [],
                'http://localhost/reset-password',
                'GET',
            ))->withQueryParams(['token' => $issued['raw_token']]),
        );

        self::assertSame(302, $response->getStatusCode());
        $headers = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('acad_reset_confirm=', $headers);
        self::assertStringNotContainsString('acad_email_confirm=', $headers);
    }

    public function testRateLimitReturns429ForValidAndInvalidAndDoesNotConsume(): void
    {
        $issued = $this->issueToken(TokenPurpose::EMAIL_VERIFY);
        $ip = '198.51.100.99';

        $lastValid = null;
        for ($i = 0; $i < 21; ++$i) {
            $lastValid = ApplicationFactory::handle(
                (new ServerRequest(
                    ['REMOTE_ADDR' => $ip],
                    [],
                    'http://localhost/verify-email',
                    'GET',
                ))->withQueryParams(['token' => $issued['raw_token']]),
            );
        }
        self::assertSame(429, $lastValid?->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT consumed_at, current_marker FROM verification_tokens WHERE verification_token_id = ?',
        );
        $stmt->execute([$issued['verification_token_id']]);
        $row = $stmt->fetch();
        self::assertNull($row['consumed_at']);
        self::assertSame(1, (int) $row['current_marker']);

        // Separate IP for invalid path rate limit proof.
        $ipInvalid = '198.51.100.98';
        $lastInvalid = null;
        for ($i = 0; $i < 21; ++$i) {
            $lastInvalid = ApplicationFactory::handle(
                (new ServerRequest(
                    ['REMOTE_ADDR' => $ipInvalid],
                    [],
                    'http://localhost/verify-email',
                    'GET',
                ))->withQueryParams(['token' => str_repeat('b', 64)]),
            );
        }
        self::assertSame(429, $lastInvalid?->getStatusCode());
    }

    public function testIssueProbeRequiresCsrf(): void
    {
        $boot = $this->bootSession();
        $user = DatabaseTestCase::applicantFixture();

        $without = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01b2a/issue-token', 'POST'))
                ->withParsedBody([
                    'user_id' => $user['user_id'],
                    'purpose' => TokenPurpose::EMAIL_VERIFY,
                    'email' => 'x@example.test',
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(403, $without->getStatusCode());

        $with = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01b2a/issue-token', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    'user_id' => $user['user_id'],
                    'purpose' => TokenPurpose::EMAIL_VERIFY,
                    'email' => 'x@example.test',
                    '_csrf' => $boot['csrf'],
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(200, $with->getStatusCode());
        $payload = json_decode((string) $with->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('raw_token', $payload);
        self::assertSame(64, strlen($payload['raw_token']));
    }

    /**
     * @param array{session: string, csrf: string}|null $boot
     * @return array{verification_token_id: int, raw_token: string}
     */
    private function issueToken(string $purpose, ?array $boot = null): array
    {
        $boot ??= $this->bootSession();
        $user = DatabaseTestCase::applicantFixture();
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01b2a/issue-token', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withHeader('Accept', 'application/json')
                ->withParsedBody([
                    'user_id' => $user['user_id'],
                    'purpose' => $purpose,
                    'email' => 'probe@example.test',
                    '_csrf' => $boot['csrf'],
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{verification_token_id: int, raw_token: string} */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
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
     * @param list<string> $setCookies
     */
    private function cookieValue(array $setCookies, string $name): ?string
    {
        foreach ($setCookies as $header) {
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
