<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class DraftApplicationHttpTest extends TestCase
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

    public function testMissingCsrfCookieReturns403(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/applications', 'POST'))
                ->withParsedBody(['batch_id' => (string) $seeded['batch_id']]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMissingCsrfTokenWithValidSessionReturns403(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $boot = $this->bootApplicant();

        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/applications', 'POST'))
                ->withParsedBody(['batch_id' => (string) $seeded['batch_id']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testValidCsrfWithNoBoundUserReturns401(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $anonymous = DatabaseTestCase::anonymousSessionFixture();

        $response = $this->post('/applications', [
            'session' => $anonymous['session'],
            'csrf' => $anonymous['csrf'],
            'user_id' => 0,
        ], ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testPendingVerificationApplicantDeniedEvenThoughRoleGrantsPermission(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $user = DatabaseTestCase::createSyntheticUser(
            'draftapp.pending.' . bin2hex(random_bytes(4)) . '@example.test',
            '+91' . random_int(6000000000, 9999999999),
            [RoleKeys::APPLICANT],
            AccountStatus::PENDING_VERIFICATION,
        );
        $bound = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $boot = ['session' => $bound['session'], 'csrf' => $bound['csrf'], 'user_id' => $user['user_id']];

        $response = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testApplicantCreateRedirectsToApplicationShow(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $boot = $this->bootApplicant();

        $response = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertMatchesRegularExpression('#^/applications/\d+$#', $response->getHeaderLine('Location'));
    }

    public function testFinanceUserCannotCreateApplicationReturns403(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $boot = $this->bootFinance();

        $response = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testClosedBatchReturns422(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCourse();
        $batchId = DatabaseTestCase::seedBatch($seeded['version_id'], ['status' => BatchStatus::PLANNED]);
        $boot = $this->bootApplicant();

        $response = $this->post('/applications', $boot, ['batch_id' => (string) $batchId]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testUnknownBatchReturns404(): void
    {
        $boot = $this->bootApplicant();

        $response = $this->post('/applications', $boot, ['batch_id' => '999999']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDuplicateCreateForSameBatchReturnsSameApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $boot = $this->bootApplicant();

        $first = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);
        $second = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame($first->getHeaderLine('Location'), $second->getHeaderLine('Location'));
    }

    public function testOwnerCanViewOwnApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $boot = $this->bootApplicant();

        $created = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);
        $location = $created->getHeaderLine('Location');

        $response = $this->get($location, $boot);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testOtherUserCannotViewSomeoneElsesApplicationReturns404(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $owner = $this->bootApplicant();
        $created = $this->post('/applications', $owner, ['batch_id' => (string) $seeded['batch_id']]);
        $location = $created->getHeaderLine('Location');

        $intruder = $this->bootApplicant();
        $response = $this->get($location, $intruder);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUnauthenticatedShowReturns401(): void
    {
        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/applications/1', 'GET'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array{session: string, csrf: string, user_id: int}
     */
    private function bootApplicant(): array
    {
        $user = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        return [
            'session' => $boot['session'],
            'csrf' => $boot['csrf'],
            'user_id' => $user['user_id'],
        ];
    }

    /**
     * @return array{session: string, csrf: string, user_id: int}
     */
    private function bootFinance(): array
    {
        $user = DatabaseTestCase::financeFixture();
        $boot = DatabaseTestCase::bindSessionForUser($user['user_id'], $user['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        return [
            'session' => $boot['session'],
            'csrf' => $boot['csrf'],
            'user_id' => $user['user_id'],
        ];
    }

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     */
    private function get(string $path, array $boot): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }

    /**
     * @param array{session: string, csrf: string, user_id: int} $boot
     * @param array<string, string> $body
     */
    private function post(string $path, array $boot, array $body): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, 'POST'))
                ->withHeader('X-CSRF-Token', $boot['csrf'])
                ->withParsedBody($body + ['_csrf' => $boot['csrf']])
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
