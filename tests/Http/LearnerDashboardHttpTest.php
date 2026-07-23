<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearnerDashboardHttpTest extends TestCase
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

    public function testDashboardReturns200ForPaymentPendingApplicant(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $response = $this->get('/dashboard', $fixture['applicant_session']);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Dashboard', $body);
        self::assertStringContainsString('Payment required', $body);
    }

    public function testDashboardIdorHidesOtherLearnersApplicationNumbers(): void
    {
        $owner = PaymentTestFixture::seedPaymentPendingApplication();
        $intruder = PaymentTestFixture::seedPaymentPendingApplication();

        $ownerNumber = $this->applicationNumber($owner['application_id']);
        $intruderNumber = $this->applicationNumber($intruder['application_id']);

        $response = $this->get('/dashboard', $intruder['applicant_session']);
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        self::assertStringContainsString($intruderNumber, $body);
        self::assertStringNotContainsString($ownerNumber, $body);
    }

    public function testPendingVerificationAndSuspendedDenied(): void
    {
        $pending = DatabaseTestCase::createSyntheticUser(
            'dash.pending.' . bin2hex(random_bytes(3)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::PENDING_VERIFICATION,
        );
        $pendingSession = DatabaseTestCase::bindSessionForUser(
            $pending['user_id'],
            $pending['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $pendingResponse = $this->get('/dashboard', $pendingSession);
        self::assertSame(403, $pendingResponse->getStatusCode());

        $suspended = DatabaseTestCase::createSyntheticUser(
            'dash.susp.' . bin2hex(random_bytes(3)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::SUSPENDED,
        );
        $suspendedSession = DatabaseTestCase::bindSessionForUser(
            $suspended['user_id'],
            $suspended['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $suspendedResponse = $this->get('/dashboard', $suspendedSession);
        // Suspended sessions are invalidated to guest (401) or denied (403).
        self::assertContains($suspendedResponse->getStatusCode(), [401, 403]);
    }

    public function testAdminNotificationRetryGetIsRejected(): void
    {
        $admin = DatabaseTestCase::superAdminFixture();
        $session = DatabaseTestCase::bindSessionForUser(
            $admin['user_id'],
            $admin['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = $this->get('/admin/notifications/1/retry', [
            'session' => $session['session'],
            'csrf' => $session['csrf'],
        ]);

        self::assertContains($response->getStatusCode(), [404, 405]);
    }

    public function testAdminNotificationRetryRequiresCsrf(): void
    {
        $admin = DatabaseTestCase::superAdminFixture();
        $session = DatabaseTestCase::bindSessionForUser(
            $admin['user_id'],
            $admin['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/admin/notifications/1/retry', 'POST'))
                ->withParsedBody([])
                ->withCookieParams([
                    $this->sessionCookieName => $session['session'],
                    $this->csrfCookieName => $session['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testApplicantDeniedAdminNotifications(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $response = $this->get('/admin/notifications', $fixture['applicant_session']);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testSuperAdminCanListNotifications(): void
    {
        $admin = DatabaseTestCase::superAdminFixture();
        $session = DatabaseTestCase::bindSessionForUser(
            $admin['user_id'],
            $admin['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $response = $this->get('/admin/notifications', [
            'session' => $session['session'],
            'csrf' => $session['csrf'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Notification', (string) $response->getBody());
    }

    public function testPaymentResultStillSaysConfirmingWhenPending(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $session = $fixture['applicant_session'];

        $initiate = $this->post(
            '/applications/' . $fixture['application_id'] . '/payments',
            $session,
            [],
            ['Accept' => 'application/json'],
        );
        self::assertSame(201, $initiate->getStatusCode());
        $payload = json_decode((string) $initiate->getBody(), true);
        self::assertIsArray($payload);
        $paymentId = (int) $payload['payment_id'];

        $return = $this->post(
            '/applications/' . $fixture['application_id'] . '/payments/' . $paymentId . '/checkout-return',
            $session,
            [],
        );
        self::assertSame(303, $return->getStatusCode());

        $result = $this->get(
            '/applications/' . $fixture['application_id'] . '/payment-result',
            $session,
        );
        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('Confirming payment', $body);
        self::assertStringNotContainsString('Payment recorded as successful', $body);
    }

    private function applicationNumber(int $applicationId): string
    {
        $stmt = DatabaseTestCase::pdo()->prepare(
            'SELECT application_number FROM applications WHERE application_id = ?',
        );
        $stmt->execute([$applicationId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, string> $headers
     */
    private function get(string $path, array $boot, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest([], [], 'http://localhost' . $path, 'GET');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return ApplicationFactory::handle(
            $request->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]),
        );
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function post(string $path, array $boot, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
            ->withParsedBody($body)
            ->withHeader('X-CSRF-Token', $boot['csrf'])
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return ApplicationFactory::handle($request);
    }
}
