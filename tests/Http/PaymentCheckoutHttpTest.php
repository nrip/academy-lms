<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Payments\PaymentStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Academy\Tests\Support\PaymentTestFixture;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class PaymentCheckoutHttpTest extends TestCase
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

    public function testPaymentPageReturns200(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $response = $this->get(
            '/applications/' . $fixture['application_id'] . '/payment',
            $fixture['applicant_session'],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Checkout', $body);
        self::assertStringContainsString('payment_pending', $body);
    }

    public function testInitiatePostCreatesAttemptAndCheckoutReturnShowsConfirming(): void
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
        self::assertSame(PaymentStatus::PENDING, $payload['status']);
        $paymentId = (int) $payload['payment_id'];

        $return = $this->post(
            '/applications/' . $fixture['application_id'] . '/payments/' . $paymentId . '/checkout-return',
            $session,
            [],
        );
        self::assertSame(303, $return->getStatusCode());
        self::assertSame(
            '/applications/' . $fixture['application_id'] . '/payment-result',
            $return->getHeaderLine('Location'),
        );

        $result = $this->get(
            '/applications/' . $fixture['application_id'] . '/payment-result',
            $session,
        );
        self::assertSame(200, $result->getStatusCode());
        $body = (string) $result->getBody();
        self::assertStringContainsString('Confirming payment', $body);
        self::assertStringNotContainsString('Payment recorded as successful', $body);
    }

    public function testInitiateRequiresCsrf(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/applications/' . $fixture['application_id'] . '/payments',
                'POST',
            ))
                ->withCookieParams([
                    $this->sessionCookieName => $fixture['applicant_session']['session'],
                    $this->csrfCookieName => $fixture['applicant_session']['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testIdorPaymentPageReturns404(): void
    {
        $owner = PaymentTestFixture::seedPaymentPendingApplication();
        $intruder = PaymentTestFixture::seedPaymentPendingApplication();

        $response = $this->get(
            '/applications/' . $owner['application_id'] . '/payment',
            $intruder['applicant_session'],
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testGetMutationIsRejected(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $response = $this->get(
            '/applications/' . $fixture['application_id'] . '/payments',
            $fixture['applicant_session'],
        );

        self::assertContains($response->getStatusCode(), [404, 405]);
    }

    public function testFinanceListReturns200AndCannotDownloadDocuments(): void
    {
        $fixture = PaymentTestFixture::seedPaymentPendingApplication();
        $container = ApplicationFactory::container('testing');
        $container->get(\Academy\Application\Payments\PaymentCheckoutService::class)
            ->initiate($fixture['applicant_auth'], $fixture['application_id']);

        $list = $this->get('/finance/payments', $fixture['finance_session']);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('Payments', (string) $list->getBody());

        $download = $this->get(
            '/applications/' . $fixture['application_id']
                . '/documents/' . $fixture['submission_ids'][0] . '/download',
            $fixture['finance_session'],
        );
        self::assertSame(403, $download->getStatusCode());
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
