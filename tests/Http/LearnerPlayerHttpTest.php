<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\RBAC\RoleKeys;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearnerPlayerHttpTest extends TestCase
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

        $cookies = ApplicationFactory::securityConfig('testing')['session']['cookies'];
        $this->sessionCookieName = $cookies['session_name'];
        $this->csrfCookieName = $cookies['csrf_name'];
    }

    public function testActiveLearnerCanOutlineReadAndMarkComplete(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Intro lesson', 'Next lesson']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $enrolmentId = $enrolment['enrolment_id'];
        $content1 = $course['content_ids'][0];
        $content2 = $course['content_ids'][1];

        $dashboard = $this->request('GET', '/dashboard', $boot);
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Continue learning', (string) $dashboard->getBody());
        self::assertStringContainsString('/learning/enrolments/' . $enrolmentId, (string) $dashboard->getBody());

        $outline = $this->request('GET', '/learning/enrolments/' . $enrolmentId, $boot);
        self::assertSame(200, $outline->getStatusCode());
        $outlineHtml = (string) $outline->getBody();
        self::assertStringContainsString('Intro lesson', $outlineHtml);
        self::assertStringContainsString('0 / 2 complete', $outlineHtml);

        $item = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $content1, $boot);
        self::assertSame(200, $item->getStatusCode());
        self::assertStringContainsString('Body for Intro lesson', (string) $item->getBody());
        self::assertStringContainsString('Mark complete', (string) $item->getBody());

        $locked = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $content2, $boot);
        self::assertSame(409, $locked->getStatusCode());

        $complete = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $content1 . '/complete',
            $boot,
        );
        self::assertSame(303, $complete->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $status = $pdo->prepare(
            'SELECT completion_status FROM content_progress WHERE enrolment_id = :e AND content_id = :c',
        );
        $status->execute(['e' => $enrolmentId, 'c' => $content1]);
        self::assertSame(ContentProgressCompletionStatus::COMPLETED, $status->fetchColumn());

        $outline2 = $this->request('GET', '/learning/enrolments/' . $enrolmentId, $boot);
        self::assertStringContainsString('1 / 2 complete', (string) $outline2->getBody());

        $item2 = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $content2, $boot);
        self::assertSame(200, $item2->getStatusCode());
    }

    public function testCannotAccessAnotherLearnersEnrolment(): void
    {
        $owner = DatabaseTestCase::applicantFixture();
        $other = DatabaseTestCase::applicantFixture();
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Solo lesson']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $owner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );

        $bootOther = DatabaseTestCase::bindSessionForUser($other['user_id'], $other['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request('GET', '/learning/enrolments/' . $enrolment['enrolment_id'], $bootOther);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testFinanceCannotAccessPlayer(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Finance blocked']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );

        $finance = DatabaseTestCase::financeFixture();
        $boot = DatabaseTestCase::bindSessionForUser($finance['user_id'], $finance['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $response = $this->request('GET', '/learning/enrolments/' . $enrolment['enrolment_id'], $boot);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testApplicantHasLearningPermission(): void
    {
        $repo = new PdoPermissionRepository(DatabaseTestCase::connectionFactory());
        self::assertContains('learning.content.access', $repo->permissionKeysForRoleKey(RoleKeys::APPLICANT));
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $boot, array $body = []): ResponseInterface
    {
        $request = (new ServerRequest([], [], 'http://localhost' . $path, $method))
            ->withCookieParams([
                $this->sessionCookieName => $boot['session'],
                $this->csrfCookieName => $boot['csrf'],
            ]);
        if ($method !== 'GET') {
            $request = $request->withParsedBody($body + ['_csrf' => $boot['csrf']]);
        }

        return ApplicationFactory::handle($request);
    }
}
