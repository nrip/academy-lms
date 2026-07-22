<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Adapted from Wp01b2aTokenProbeHttpTest for the now-public EmailVerificationController.
 * Tokens are issued in-process via VerificationTokenIssuer (no test-only probe route exists
 * for the public registration flow).
 */
final class EmailVerificationHttpTest extends TestCase
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

    public function testValidGetRedirectsToConfirmAndSetsConfirmationCookie(): void
    {
        $userId = $this->insertRawUser('emailhttp.valid');
        $issued = $this->issueToken($userId);

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.40'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => $issued['raw_token']]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-email/confirm', $response->getHeaderLine('Location'));
        $secret = $this->cookieValue($response->getHeader('Set-Cookie'), 'acad_email_confirm');
        self::assertNotNull($secret);
    }

    public function testInvalidTokenRedirectsToResultInvalid(): void
    {
        $response = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.41'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => str_repeat('a', 64)]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-email/result?status=invalid_or_expired', $response->getHeaderLine('Location'));
    }

    public function testConfirmPageShowsFormWhenCookiePresent(): void
    {
        $userId = $this->insertRawUser('emailhttp.confirmpage');
        $issued = $this->issueToken($userId);
        $boot = $this->bootSession();

        $get = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.42'],
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

        $confirmPage = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-email/confirm', 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                    'acad_email_confirm' => $secret,
                ]),
        );

        self::assertSame(200, $confirmPage->getStatusCode());
        self::assertStringContainsString('<form', (string) $confirmPage->getBody());
    }

    public function testConfirmPostWithoutCsrfReturns403(): void
    {
        $userId = $this->insertRawUser('emailhttp.nocsrf');
        $issued = $this->issueToken($userId);
        $boot = $this->bootSession();

        $get = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.43'],
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

    public function testSuccessfulConfirmActivatesUserAndRendersResultPage(): void
    {
        $userId = $this->insertRawUser('emailhttp.success');
        $issued = $this->issueToken($userId);
        $boot = $this->bootSession();

        $get = ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '198.51.100.44'],
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

        $confirm = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-email/confirm', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                    'acad_email_confirm' => $secret,
                ]),
        );

        self::assertSame(302, $confirm->getStatusCode());
        self::assertSame('/verify-email/result?status=success', $confirm->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        self::assertNotNull($row['email_verified_at']);
        self::assertSame(AccountStatus::ACTIVE, $row['account_status']);

        $resultPage = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-email/result', 'GET'))
                ->withQueryParams(['status' => 'success']),
        );
        self::assertSame(200, $resultPage->getStatusCode());
        self::assertStringContainsString('Confirmation completed.', (string) $resultPage->getBody());
    }

    /**
     * @return array{verification_token_id: int, raw_token: string}
     */
    private function issueToken(int $userId): array
    {
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);

        return $issuer->issue(
            $userId,
            TokenPurpose::EMAIL_VERIFY,
            'http-verify@example.test',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );
    }

    private function insertRawUser(string $label): int
    {
        $pdo = DatabaseTestCase::pdo();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $hash = password_hash('emailhttp-fixture-password-1', PASSWORD_ARGON2ID);
        $stmt = $pdo->prepare(
            'INSERT INTO users (
                email, email_verified_at, mobile_e164, mobile_verified_at, password_hash,
                account_status, failed_login_count, locked_until, auth_version,
                password_changed_at, terms_accepted_at, terms_version,
                privacy_accepted_at, privacy_version, timezone, created_at, updated_at
            ) VALUES (
                ?, NULL, ?, NULL, ?,
                ?, 0, NULL, 1,
                ?, ?, ?,
                ?, ?, ?, ?, ?
            )',
        );
        $stmt->execute([
            strtolower($label) . '.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            $hash,
            AccountStatus::PENDING_VERIFICATION,
            $now,
            $now,
            'emailhttp.test.terms.v0',
            $now,
            'emailhttp.test.privacy.v0',
            'Asia/Kolkata',
            $now,
            $now,
        ]);

        return (int) $pdo->lastInsertId();
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
