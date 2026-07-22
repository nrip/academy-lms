<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Notifications\SealedSecret;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class MobileVerificationHttpTest extends TestCase
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

    public function testSuccessfulOtpVerificationRedirectsWithSuccessStatus(): void
    {
        $boot = $this->registerAndBoot('mobilehttp.success');
        $otp = $this->extractCurrentOtp($boot['user_id']);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-mobile', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['otp' => $otp, '_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-mobile?status=success', $response->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT mobile_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$boot['user_id']]);
        self::assertNotNull($stmt->fetchColumn());
    }

    public function testWrongOtpRedirectsWithInvalidStatus(): void
    {
        $boot = $this->registerAndBoot('mobilehttp.wrong');

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-mobile', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['otp' => '000000', '_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-mobile?status=invalid', $response->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT mobile_verified_at FROM users WHERE user_id = ?');
        $stmt->execute([$boot['user_id']]);
        self::assertNull($stmt->fetchColumn());
    }

    public function testResendIssuesNewChallengeAndClearsOldCurrentMarker(): void
    {
        $boot = $this->registerAndBoot('mobilehttp.resend');
        $pdo = DatabaseTestCase::pdo();
        $before = $pdo->prepare('SELECT verification_challenge_id FROM verification_challenges WHERE user_id = ? AND current_marker = 1');
        $before->execute([$boot['user_id']]);
        $originalChallengeId = (int) $before->fetchColumn();
        self::assertGreaterThan(0, $originalChallengeId);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-mobile/resend', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody(['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/verify-mobile?status=resent', $response->getHeaderLine('Location'));

        $current = $pdo->prepare('SELECT COUNT(*) FROM verification_challenges WHERE user_id = ? AND current_marker = 1');
        $current->execute([$boot['user_id']]);
        self::assertSame(1, (int) $current->fetchColumn(), 'Exactly one current challenge must remain.');

        $old = $pdo->prepare('SELECT current_marker FROM verification_challenges WHERE verification_challenge_id = ?');
        $old->execute([$originalChallengeId]);
        self::assertNull($old->fetchColumn(), 'The prior challenge must no longer be current.');
    }

    public function testMissingCsrfReturns403(): void
    {
        $boot = $this->registerAndBoot('mobilehttp.nocsrf');
        $otp = $this->extractCurrentOtp($boot['user_id']);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/verify-mobile', 'POST'))
                ->withParsedBody(['otp' => $otp])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return array{session: string, csrf: string, user_id: int}
     */
    private function registerAndBoot(string $label): array
    {
        $boot = $this->bootSession();
        $email = $label . '.' . bin2hex(random_bytes(4)) . '@example.test';
        $mobile = '9' . random_int(100000000, 999999999);

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/register', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    'email' => $email,
                    'mobile' => $mobile,
                    'password' => 'a-strong-password-mobile-1',
                    'terms_accepted' => '1',
                    'privacy_accepted' => '1',
                    '_csrf' => $boot['csrf'],
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        self::assertSame(302, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $userId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $userId);

        return ['session' => $boot['session'], 'csrf' => $boot['csrf'], 'user_id' => $userId];
    }

    private function extractCurrentOtp(int $userId): string
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare(
            'SELECT verification_challenge_id, otp_delivery_ciphertext, otp_delivery_nonce, otp_delivery_key_version
             FROM verification_challenges
             WHERE user_id = ? AND current_marker = 1',
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        self::assertNotFalse($row, 'Expected a current SMS challenge for the registered user.');

        $container = ApplicationFactory::container('testing');
        /** @var SealedSecretBox $sealedBox */
        $sealedBox = $container->get(SealedSecretBox::class);

        $sealed = new SealedSecret(
            $row['otp_delivery_ciphertext'],
            $row['otp_delivery_nonce'],
            (int) $row['otp_delivery_key_version'],
        );
        $plaintext = $sealedBox->unseal(
            $sealed,
            SealedSecretBox::challengeAad((int) $row['verification_challenge_id'], 'sms', $userId),
        );
        $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        return (string) $decoded['otp'];
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
