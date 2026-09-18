<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class LearnerPersonalLearningHttpTest extends TestCase
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

    public function testLearnerBookmarksNotesGoalsArePrivateAndDoNotTouchProgress(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $peer = DatabaseTestCase::applicantFixture();
        $finance = DatabaseTestCase::financeFixture();

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

        $enrolmentId = (int) $enrolment['enrolment_id'];
        $contentId = (int) $course['content_ids'][0];
        $learnerBoot = DatabaseTestCase::bindSessionForUser(
            $learner['user_id'],
            $learner['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $item = $this->request('GET', '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId, $learnerBoot);
        self::assertSame(200, $item->getStatusCode());
        $itemBody = (string) $item->getBody();
        self::assertStringContainsString('Save for later', $itemBody);
        self::assertStringContainsString('Private notes', $itemBody);

        $pdo = DatabaseTestCase::pdo();
        $progressBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM content_progress WHERE enrolment_id = ' . $enrolmentId
            . " AND completion_status = 'completed'",
        )->fetchColumn();

        $bookmark = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId . '/bookmark',
            $learnerBoot,
        );
        self::assertSame(303, $bookmark->getStatusCode());

        $note = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId . '/notes',
            $learnerBoot,
            ['body' => 'Remember titration discussion for clinic.'],
        );
        self::assertSame(303, $note->getStatusCode());

        $goalDate = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+14 days')->format('Y-m-d');
        $goal = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/goal',
            $learnerBoot,
            ['label' => 'Finish by mid-month', 'target_date' => $goalDate],
        );
        self::assertSame(303, $goal->getStatusCode());

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM learner_lesson_bookmarks')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM learner_lesson_notes')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM learner_enrolment_goals')->fetchColumn());
        self::assertSame(
            $progressBefore,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM content_progress WHERE enrolment_id = ' . $enrolmentId
                . " AND completion_status = 'completed'",
            )->fetchColumn(),
        );

        $noteBody = (string) $pdo->query('SELECT body FROM learner_lesson_notes LIMIT 1')->fetchColumn();
        self::assertStringContainsString('titration', $noteBody);

        $auditBodies = $pdo->query(
            "SELECT previous_value, new_value FROM audit_log
             WHERE action IN ('learning.note.created', 'learning.note.updated')
             ORDER BY audit_id DESC LIMIT 5",
        )->fetchAll();
        foreach ($auditBodies as $row) {
            self::assertStringNotContainsString('titration', (string) $row['previous_value']);
            self::assertStringNotContainsString('titration', (string) $row['new_value']);
        }

        $outline = $this->request('GET', '/learning/enrolments/' . $enrolmentId, $learnerBoot);
        $outlineBody = (string) $outline->getBody();
        self::assertStringContainsString('Finish by mid-month', $outlineBody);
        self::assertStringContainsString('Saved for later', $outlineBody);
        self::assertStringContainsString('Intro lesson', $outlineBody);

        $peerBoot = DatabaseTestCase::bindSessionForUser($peer['user_id'], $peer['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $peerItem = $this->request(
            'GET',
            '/learning/enrolments/' . (int) $peerEnrolment['enrolment_id'] . '/items/' . $contentId,
            $peerBoot,
        );
        $peerBody = (string) $peerItem->getBody();
        self::assertStringNotContainsString('titration', $peerBody);
        self::assertStringContainsString('Save for later', $peerBody);

        $financeBoot = DatabaseTestCase::bindSessionForUser(
            $finance['user_id'],
            $finance['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        self::assertSame(
            403,
            $this->request(
                'POST',
                '/learning/enrolments/' . $enrolmentId . '/items/' . $contentId . '/notes',
                $financeBoot,
                ['body' => 'should not work'],
            )->getStatusCode(),
        );
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
