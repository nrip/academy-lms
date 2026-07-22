<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearnerProfileHttpTest extends TestCase
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

    public function testUnauthenticatedProfileReturns401(): void
    {
        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/profile', 'GET'),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function testOverviewReturns200(): void
    {
        $boot = $this->bootApplicant();
        $response = $this->get('/profile', $boot);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('completeness', strtolower((string) $response->getBody()));
    }

    public function testShowPersonalReturnsForm(): void
    {
        $boot = $this->bootApplicant();
        $response = $this->get('/profile/personal', $boot);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="first_name"', (string) $response->getBody());
        self::assertStringContainsString('name="row_version"', (string) $response->getBody());
    }

    public function testUpdatePersonalRedirectsOnSuccess(): void
    {
        $boot = $this->bootApplicant();
        $response = $this->post('/profile/personal', $boot, [
            'row_version' => '1',
            'first_name' => 'Asha',
            'last_name' => 'Rao',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/profile/personal?saved=1', $response->getHeaderLine('Location'));

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT first_name, row_version FROM learner_profiles WHERE user_id = ?');
        $stmt->execute([$boot['user_id']]);
        $row = $stmt->fetch();
        self::assertSame('Asha', $row['first_name']);
        self::assertSame(2, (int) $row['row_version']);
    }

    public function testUpdatePersonalValidationReturns422(): void
    {
        $boot = $this->bootApplicant();
        $response = $this->post('/profile/personal', $boot, [
            'row_version' => '1',
            'date_of_birth' => 'not-a-date',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    public function testUpdatePersonalStaleVersionReturns409(): void
    {
        $boot = $this->bootApplicant();
        $first = $this->post('/profile/personal', $boot, ['row_version' => '1', 'first_name' => 'A']);
        self::assertSame(303, $first->getStatusCode());

        $second = $this->post('/profile/personal', $boot, ['row_version' => '1', 'first_name' => 'B']);
        self::assertSame(409, $second->getStatusCode());
    }

    public function testUpdateProfessionalRedirectsOnSuccess(): void
    {
        $boot = $this->bootApplicant();
        $response = $this->post('/profile/professional', $boot, [
            'row_version' => '1',
            'profession' => 'Physician',
            'years_of_experience' => '10',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/profile/professional?saved=1', $response->getHeaderLine('Location'));
    }

    public function testQualificationAddAndList(): void
    {
        $boot = $this->bootApplicant();
        $add = $this->post('/profile/qualifications', $boot, [
            'qualification_type' => 'Degree',
            'qualification_name' => 'MBBS',
            'institution_name' => 'AIIMS',
            'completion_year' => '2010',
        ]);
        self::assertSame(303, $add->getStatusCode());

        $list = $this->get('/profile/qualifications', $boot);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('MBBS', (string) $list->getBody());
    }

    public function testMissingCsrfReturns403(): void
    {
        $boot = $this->bootApplicant();
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost/profile/personal', 'POST'))
                ->withParsedBody(['row_version' => '1', 'first_name' => 'NoCsrf'])
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
    private function bootApplicant(): array
    {
        $user = DatabaseTestCase::applicantFixture();
        DatabaseTestCase::ensureLearnerProfileStub($user['user_id']);
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
