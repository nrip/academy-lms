<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class RegistrationHttpTest extends TestCase
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

    public function testShowFormReturns200(): void
    {
        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/register', 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="email"', (string) $response->getBody());
        self::assertStringContainsString('name="terms_accepted"', (string) $response->getBody());
        self::assertStringContainsString('name="privacy_accepted"', (string) $response->getBody());
    }

    public function testPostWithCsrfCreatesUserAndRedirectsToPending(): void
    {
        $boot = $this->bootSession();
        $email = 'httpreg.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $response = $this->postRegistration($boot, $email, $mobile, 'a-strong-password-http-1');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/register/pending', $response->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT user_id, account_status FROM users WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch();
        self::assertNotFalse($row);
        self::assertSame('pending_verification', $row['account_status']);
    }

    public function testSessionRemainsAnonymousButCarriesPendingVerificationMarker(): void
    {
        $boot = $this->bootSession();
        $email = 'httpsession.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $this->postRegistration($boot, $email, $mobile, 'a-strong-password-http-2');

        $probe = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01a/probe', 'GET'))
                ->withHeader('Accept', 'application/json')
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        $payload = json_decode((string) $probe->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['authenticated']);
        $sessionId = $payload['session_id'];
        self::assertIsInt($sessionId);

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT user_id, payload FROM sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        self::assertNotFalse($row);
        self::assertNull($row['user_id'], 'Registration must not authenticate the anonymous session.');

        $sessionPayload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('pending_verification_user_id', $sessionPayload);

        $userStmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $userStmt->execute([strtolower($email)]);
        $userId = (int) $userStmt->fetchColumn();
        self::assertSame($userId, $sessionPayload['pending_verification_user_id']);
    }

    public function testDuplicatePostReturnsSameStatusAndRedirectLocation(): void
    {
        $email = 'httpdup.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $bootA = $this->bootSession();
        $first = $this->postRegistration($bootA, $email, $mobile, 'a-strong-password-http-3');

        $bootB = $this->bootSession();
        $second = $this->postRegistration($bootB, $email, $mobile, 'a-different-password-http-4');

        self::assertSame($first->getStatusCode(), $second->getStatusCode());
        self::assertSame($first->getHeaderLine('Location'), $second->getHeaderLine('Location'));
        self::assertSame(302, $second->getStatusCode());
        self::assertSame('/register/pending', $second->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute([strtolower($email)]);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testMissingCsrfReturns403(): void
    {
        $boot = $this->bootSession();
        $email = 'httpnocsrf.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/register', 'POST'))
                ->withParsedBody([
                    'email' => $email,
                    'mobile' => $mobile,
                    'password' => 'a-strong-password-http-5',
                    'terms_accepted' => '1',
                    'privacy_accepted' => '1',
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $count->execute([strtolower($email)]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /**
     * @param array{session: string, csrf: string} $boot
     */
    private function postRegistration(array $boot, string $email, string $mobile, string $password): \Psr\Http\Message\ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/register', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    'email' => $email,
                    'mobile' => $mobile,
                    'password' => $password,
                    'terms_accepted' => '1',
                    'privacy_accepted' => '1',
                    '_csrf' => $boot['csrf'],
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
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
