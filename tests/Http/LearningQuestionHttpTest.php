<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearningQuestionHttpTest extends TestCase
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

    public function testLearnerAsksFacultyRespondsAndPeersAreIsolated(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $peer = DatabaseTestCase::applicantFixture();
        $faculty = DatabaseTestCase::courseAdminFixture();
        $outsider = DatabaseTestCase::courseAdminFixture();
        $finance = DatabaseTestCase::financeFixture();
        $reviewer = DatabaseTestCase::reviewerFixture();

        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Intro lesson', 'Next lesson']);
        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $peerEnrolment = DatabaseTestCase::seedActiveEnrolment(
            $peer['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $this->assign($faculty['user_id'], (int) $course['course_id']);
        $this->assign($outsider['user_id'], (int) DatabaseTestCase::seedPublishedCourse()['course_id']);

        $enrolmentId = (int) $enrolment['enrolment_id'];
        $contentId = (int) $course['content_ids'][0];
        $learnerBoot = DatabaseTestCase::bindSessionForUser(
            $learner['user_id'],
            $learner['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $item = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId, $learnerBoot);
        self::assertSame(200, $item->getStatusCode());
        self::assertStringContainsString('Ask a question', (string) $item->getBody());
        self::assertStringNotContainsString('Answer', (string) $item->getBody());

        $ask = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId . '/questions',
            $learnerBoot,
            ['body' => 'How does titration dosing work in this lesson?'],
        );
        self::assertSame(303, $ask->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $questionId = (int) $pdo->query('SELECT question_id FROM learning_questions ORDER BY question_id DESC LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $questionId);

        $askedEvents = $pdo->prepare(
            'SELECT COUNT(*) FROM outbox_messages WHERE event_type = :type AND idempotency_key = :key',
        );
        $askedEvents->execute([
            'type' => TransactionalNotificationEventTypes::QUESTION_ASKED,
            'key' => TransactionalNotificationEventTypes::QUESTION_ASKED . ':' . $questionId . ':' . $faculty['user_id'],
        ]);
        self::assertSame(1, (int) $askedEvents->fetchColumn());

        $thread = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId . '?asked=1', $learnerBoot);
        self::assertStringContainsString('Waiting for a response', (string) $thread->getBody());
        self::assertStringContainsString('How does titration dosing work', (string) $thread->getBody());

        $peerBoot = DatabaseTestCase::bindSessionForUser($peer['user_id'], $peer['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $peerItem = $this->request(
            'GET',
            '/learning/enrolments/' . (int) $peerEnrolment['enrolment_id'] . '/items/' . $contentId,
            $peerBoot,
        );
        self::assertStringNotContainsString('How does titration dosing work', (string) $peerItem->getBody());

        $facultyBoot = DatabaseTestCase::bindSessionForUser(
            $faculty['user_id'],
            $faculty['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        $facultyHome = $this->request('GET', '/faculty', $facultyBoot);
        self::assertSame(200, $facultyHome->getStatusCode());
        $facultyHomeBody = (string) $facultyHome->getBody();
        self::assertStringContainsString('Intro lesson', $facultyHomeBody);
        self::assertStringContainsString('/faculty/questions/' . $questionId, $facultyHomeBody);

        $detail = $this->request('GET', '/faculty/questions/' . $questionId, $facultyBoot);
        self::assertSame(200, $detail->getStatusCode());
        $detailBody = (string) $detail->getBody();
        self::assertStringContainsString('Intro lesson', $detailBody);
        self::assertStringContainsString('Response', $detailBody);
        self::assertStringNotContainsString('Answer', $detailBody);

        $respond = $this->request(
            'POST',
            '/faculty/questions/' . $questionId . '/responses',
            $facultyBoot,
            ['body' => 'Start low and titrate carefully as discussed in the lesson.'],
        );
        self::assertSame(303, $respond->getStatusCode());

        $second = $this->request(
            'POST',
            '/faculty/questions/' . $questionId . '/responses',
            $facultyBoot,
            ['body' => 'Also review the accompanying notes.'],
        );
        self::assertSame(303, $second->getStatusCode());
        self::assertSame(
            2,
            (int) $pdo->query('SELECT COUNT(*) FROM learning_question_responses WHERE question_id = ' . $questionId)->fetchColumn(),
        );

        $respondedEvents = (int) $pdo->query(
            "SELECT COUNT(*) FROM outbox_messages WHERE event_type = '"
            . TransactionalNotificationEventTypes::QUESTION_RESPONDED . "'",
        )->fetchColumn();
        self::assertSame(2, $respondedEvents);

        $learnerView = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId, $learnerBoot);
        $learnerBody = (string) $learnerView->getBody();
        self::assertStringContainsString('Responded', $learnerBody);
        self::assertStringContainsString('Start low and titrate', $learnerBody);
        self::assertStringContainsString('Also review the accompanying notes', $learnerBody);

        $outsiderBoot = DatabaseTestCase::bindSessionForUser(
            $outsider['user_id'],
            $outsider['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        self::assertSame(403, $this->request('GET', '/faculty/questions/' . $questionId, $outsiderBoot)->getStatusCode());

        $financeBoot = DatabaseTestCase::bindSessionForUser(
            $finance['user_id'],
            $finance['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        self::assertSame(403, $this->request('GET', '/faculty/questions/' . $questionId, $financeBoot)->getStatusCode());

        $reviewerBoot = DatabaseTestCase::bindSessionForUser(
            $reviewer['user_id'],
            $reviewer['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        self::assertSame(403, $this->request('GET', '/faculty/questions/' . $questionId, $reviewerBoot)->getStatusCode());
    }

    private function assign(int $adminUserId, int $courseId): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        DatabaseTestCase::pdo()->prepare(
            'INSERT INTO course_admin_scope_assignments (
                admin_user_id, scope_type, course_id, course_version_id, include_future_versions,
                effective_from, effective_to, created_by_user_id, created_at, updated_at
             ) VALUES (
                :admin, :type, :course_id, NULL, 1, :from, NULL, :admin2, :created, :updated
             )',
        )->execute([
            'admin' => $adminUserId,
            'type' => 'course',
            'course_id' => $courseId,
            'from' => $now,
            'admin2' => $adminUserId,
            'created' => $now,
            'updated' => $now,
        ]);
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
