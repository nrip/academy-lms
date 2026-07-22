<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Notifications\DeliveryFinaliser;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Identity\TokenPurpose;
use Academy\Domain\Notifications\IdentityNotificationEventTypes;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use InvalidArgumentException;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class Wp01b2aNotificationSecurityTest extends TestCase
{
    private string $sessionCookieName;
    private string $csrfCookieName;
    private string $logPath;

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
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/test.log';
        if (is_file($this->logPath)) {
            file_put_contents($this->logPath, '');
        }
    }

    public function testHttpAndWorkerDoNotLeakRawTokenOrOtp(): void
    {
        $boot = $this->bootSession();
        $user = DatabaseTestCase::applicantFixture();
        $issue = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/__wp01b2a/issue-token', 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody([
                    'user_id' => $user['user_id'],
                    'purpose' => TokenPurpose::EMAIL_VERIFY,
                    'email' => 'sec@example.test',
                    '_csrf' => $boot['csrf'],
                ])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
        $payload = json_decode((string) $issue->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $rawToken = $payload['raw_token'];

        ApplicationFactory::handle(
            (new ServerRequest(
                ['REMOTE_ADDR' => '203.0.113.50'],
                [],
                'http://localhost/verify-email',
                'GET',
            ))->withQueryParams(['token' => $rawToken]),
        );

        $container = ApplicationFactory::container('testing');
        /** @var IdentityNotificationDeliveryWorker $worker */
        $worker = $container->get(IdentityNotificationDeliveryWorker::class);
        $worker->run('sec-worker', 10);

        $log = is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
        self::assertStringNotContainsString($rawToken, $log);

        $audit = DatabaseTestCase::pdo()->query('SELECT previous_value, new_value FROM audit_log')->fetchAll();
        $joined = json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($rawToken, $joined);
        self::assertStringNotContainsString('delivery_ciphertext', $joined);

        $outbox = DatabaseTestCase::pdo()->query(
            "SELECT payload FROM outbox_messages WHERE event_type LIKE 'identity.%'",
        )->fetchAll();
        foreach ($outbox as $row) {
            $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('verification_token_id', $decoded);
            self::assertArrayNotHasKey('link_token', $decoded);
            self::assertArrayNotHasKey('email', $decoded);
            self::assertArrayNotHasKey('raw_token', $decoded);
        }

        $buckets = DatabaseTestCase::pdo()->query('SELECT bucket_key FROM rate_limit_buckets')->fetchAll();
        $bucketJoined = json_encode($buckets, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($rawToken, $bucketJoined);
    }

    public function testStagingForbidsRecordingEmailAdapter(): void
    {
        $builder = require dirname(__DIR__, 2) . '/config/security.php';
        $string = static function (string $key, string $default = '') {
            $map = [
                'RATE_LIMIT_PEPPER' => 'staging-rate-pepper',
                'TOKEN_PEPPER' => 'staging-token-pepper',
                'OTP_PEPPER' => 'staging-otp-pepper-different',
                'NOTIFICATION_DELIVERY_KEY' => base64_encode(str_repeat("\4", 32)),
                'NOTIFICATION_EMAIL_ADAPTER' => 'recording',
                'NOTIFICATION_SMS_ADAPTER' => 'unavailable',
                'NOTIFICATION_DELIVERY_KEY_VERSION' => '1',
            ];

            return $map[$key] ?? $default;
        };
        $bool = static fn (string $key, bool $default): bool => $default;
        $int = static function (string $key, int $default) use ($string): int {
            $value = $string($key, '');

            return $value === '' ? $default : (int) $value;
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recording/local email adapters are forbidden');
        $builder('staging', $bool, $string, $int);
    }

    public function testStaleFinaliserCannotClearCiphertext(): void
    {
        $user = DatabaseTestCase::applicantFixture();
        $container = ApplicationFactory::container('testing');
        /** @var VerificationTokenIssuer $issuer */
        $issuer = $container->get(VerificationTokenIssuer::class);
        $issued = $issuer->issue(
            $user['user_id'],
            TokenPurpose::EMAIL_VERIFY,
            'stale@example.test',
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        );

        $outbox = new PdoOutboxRepository(DatabaseTestCase::connectionFactory());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $claimedA = $outbox->claimByEventTypes('worker-a', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimedA);

        // Expire lease and reclaim as worker-b.
        DatabaseTestCase::pdo()->prepare(
            'UPDATE outbox_messages SET lock_expires_at = ? WHERE outbox_message_id = ?',
        )->execute([$now->modify('-1 second')->format('Y-m-d H:i:s.u'), $claimedA[0]->id]);

        $claimedB = $outbox->claimByEventTypes('worker-b', $now, 60, IdentityNotificationEventTypes::all(), 1);
        self::assertCount(1, $claimedB);

        /** @var DeliveryFinaliser $finaliser */
        $finaliser = $container->get(DeliveryFinaliser::class);

        try {
            $finaliser->finalizeDelivered('token', $issued['verification_token_id'], $claimedA[0], 'stale');
            self::fail('Expected stale finalisation to throw.');
        } catch (DomainRuleException) {
            // expected
        }

        $cipher = DatabaseTestCase::pdo()->prepare(
            'SELECT delivery_ciphertext, delivery_status FROM verification_tokens WHERE verification_token_id = ?',
        );
        $cipher->execute([$issued['verification_token_id']]);
        $row = $cipher->fetch();
        self::assertNotNull($row['delivery_ciphertext']);
        self::assertSame('pending', $row['delivery_status']);

        self::assertTrue(
            $finaliser->finalizeDelivered('token', $issued['verification_token_id'], $claimedB[0], 'winner'),
        );
        $cipher->execute([$issued['verification_token_id']]);
        $row = $cipher->fetch();
        self::assertNull($row['delivery_ciphertext']);
        self::assertSame('delivered', $row['delivery_status']);
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

            return rawurldecode(substr($pair, $eq + 1));
        }

        return null;
    }
}
