<?php

declare(strict_types=1);

namespace Academy\Tests\Security;

use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CourseBatchSecurityTest extends TestCase
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

    public function testUnpublishedCourseIsIndistinguishableFromNonExistentCourse(): void
    {
        DatabaseTestCase::seedPublishedCourse([
            'slug' => 'sec-unpublished-course',
            'title' => 'Security Unpublished Course',
            'version_status' => CourseVersionStatus::DRAFT,
            'locked' => false,
            'set_current_published_version' => false,
        ]);

        $hiddenResponse = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses/sec-unpublished-course', 'GET'),
        );
        $missingResponse = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses/does-not-exist-at-all', 'GET'),
        );

        self::assertSame(404, $hiddenResponse->getStatusCode());
        self::assertSame(404, $missingResponse->getStatusCode());
        self::assertSame(
            $this->stripRequestId((string) $missingResponse->getBody()),
            $this->stripRequestId((string) $hiddenResponse->getBody()),
            'Unpublished course must render the same 404 body as a non-existent course (no draft-state leak).',
        );
    }

    public function testUnpublishedCourseOmittedFromPublicIndex(): void
    {
        DatabaseTestCase::seedPublishedCourse([
            'slug' => 'sec-hidden-index-course',
            'title' => 'Security Hidden Index Course',
            'version_status' => CourseVersionStatus::DRAFT,
            'locked' => false,
            'set_current_published_version' => false,
        ]);

        $response = ApplicationFactory::handle(
            new ServerRequest([], [], 'http://localhost/courses', 'GET'),
        );

        self::assertStringNotContainsString('Security Hidden Index Course', (string) $response->getBody());
    }

    public function testFinanceAdminCannotCreateApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $finance = DatabaseTestCase::financeFixture();
        $boot = $this->boot($finance['user_id'], $finance['auth_version']);

        $response = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame(403, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE user_id = ?');
        $stmt->execute([$finance['user_id']]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testCredentialReviewerCannotCreateApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $reviewer = DatabaseTestCase::reviewerFixture();
        $boot = $this->boot($reviewer['user_id'], $reviewer['auth_version']);

        $response = $this->post('/applications', $boot, ['batch_id' => (string) $seeded['batch_id']]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMassAssignmentOfApplicationFieldsIsIgnored(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $victim = DatabaseTestCase::applicantFixture();
        $attacker = DatabaseTestCase::applicantFixture();
        $boot = $this->boot($attacker['user_id'], $attacker['auth_version']);

        $response = $this->post('/applications', $boot, [
            'batch_id' => (string) $seeded['batch_id'],
            'user_id' => (string) $victim['user_id'],
            'status' => 'admitted',
            'application_id' => '999999',
            'submitted_at' => '2020-01-01 00:00:00',
        ]);

        self::assertSame(303, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT user_id, status, submitted_at FROM applications WHERE batch_id = ?');
        $stmt->execute([$seeded['batch_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($row);
        self::assertSame($attacker['user_id'], (int) $row['user_id'], 'user_id must come from the session, never the request body.');
        self::assertSame('draft', $row['status']);
        self::assertNull($row['submitted_at']);
    }

    public function testIdorGetOfAnotherUsersApplicationReturns404(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $owner = DatabaseTestCase::applicantFixture();
        $ownerBoot = $this->boot($owner['user_id'], $owner['auth_version']);
        $created = $this->post('/applications', $ownerBoot, ['batch_id' => (string) $seeded['batch_id']]);
        $location = $created->getHeaderLine('Location');

        $attacker = DatabaseTestCase::applicantFixture();
        $attackerBoot = $this->boot($attacker['user_id'], $attacker['auth_version']);
        $response = ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $location, 'GET'))
                ->withCookieParams([
                    $this->sessionCookieName => $attackerBoot['session'],
                    $this->csrfCookieName => $attackerBoot['csrf'],
                ]),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRepeatedGetRequestsToCatalogueDoNotMutateState(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();

        for ($i = 0; $i < 3; $i++) {
            ApplicationFactory::handle(new ServerRequest([], [], 'http://localhost/courses', 'GET'));
            ApplicationFactory::handle(new ServerRequest([], [], 'http://localhost/courses/' . $this->slugFor($seeded['course_id']), 'GET'));
            ApplicationFactory::handle(new ServerRequest([], [], 'http://localhost/batches/' . $seeded['batch_id'], 'GET'));
        }

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'application.%'")->fetchColumn());
    }

    public function testGetRequestCannotCreateApplication(): void
    {
        $seeded = DatabaseTestCase::seedPublishedCatalogue();
        $user = DatabaseTestCase::applicantFixture();
        $boot = $this->boot($user['user_id'], $user['auth_version']);

        $response = ApplicationFactory::handle(
            (new ServerRequest(
                [],
                [],
                'http://localhost/applications?batch_id=' . $seeded['batch_id'],
                'GET',
            ))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );

        self::assertNotSame(200, $response->getStatusCode());
        self::assertNotSame(303, $response->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn());
    }

    private function stripRequestId(string $html): string
    {
        return (string) preg_replace('/Request ID: [a-f0-9]+/', 'Request ID: X', $html);
    }

    private function slugFor(int $courseId): string
    {
        $pdo = DatabaseTestCase::pdo();
        $stmt = $pdo->prepare('SELECT slug FROM courses WHERE course_id = ?');
        $stmt->execute([$courseId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array{session: string, csrf: string}
     */
    private function boot(int $userId, int $authVersion): array
    {
        $boot = DatabaseTestCase::bindSessionForUser($userId, $authVersion, AuthStage::FULLY_AUTHENTICATED);

        return ['session' => $boot['session'], 'csrf' => $boot['csrf']];
    }

    /**
     * @param array{session: string, csrf: string} $boot
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
