<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Courses\BatchStatus;
use Academy\Domain\Identity\AuthStage;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class AcademyOperationsHttpTest extends TestCase
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

    public function testAssignedAdminSeesCountsAndFacultyHomeWithoutPayments(): void
    {
        $owner = DatabaseTestCase::courseAdminFixture();
        $other = DatabaseTestCase::courseAdminFixture();
        $learner = DatabaseTestCase::applicantFixture();

        $owned = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Clinic hour']);
        $hidden = DatabaseTestCase::seedPublishedCourse(['master_title' => 'Hidden metabolic course']);
        $pdo = DatabaseTestCase::pdo();
        $now = gmdate('Y-m-d H:i:s.u');
        $pdo->prepare('UPDATE course_versions SET locked_at = NULL, locked_reason = NULL WHERE version_id = :id')
            ->execute(['id' => $owned['version_id']]);
        $pdo->prepare(
            'UPDATE content_items
             SET content_type = :type, body_text = NULL, live_join_url = :join, live_starts_at = :starts,
                 live_provider = :provider, updated_at = :updated
             WHERE content_id = :id',
        )->execute([
            'type' => 'live_session',
            'join' => 'https://meet.example.test/secret-room',
            'starts' => '2026-12-01 09:00:00',
            'provider' => 'google_meet',
            'updated' => $now,
            'id' => $owned['content_ids'][0],
        ]);
        $pdo->prepare(
            'UPDATE course_versions
             SET status = :status, published_at = :published, locked_at = :locked, locked_reason = :reason
             WHERE version_id = :id',
        )->execute([
            'status' => 'published',
            'published' => $now,
            'locked' => $now,
            'reason' => 'published',
            'id' => $owned['version_id'],
        ]);
        $pdo->prepare('UPDATE courses SET current_published_version_id = :version_id WHERE course_id = :course_id')
            ->execute(['version_id' => $owned['version_id'], 'course_id' => $owned['course_id']]);

        DatabaseTestCase::seedActiveEnrolment($learner['user_id'], $owned['course_id'], $owned['version_id'], [
            'status' => BatchStatus::IN_PROGRESS,
            'name' => 'January intake',
        ]);
        $this->assign($owner['user_id'], $owned['course_id']);
        $this->assign($other['user_id'], $hidden['course_id']);

        $ownedTitle = (string) $pdo->query(
            'SELECT master_title FROM courses WHERE course_id = ' . (int) $owned['course_id'],
        )->fetchColumn();

        $ownerBoot = DatabaseTestCase::bindSessionForUser($owner['user_id'], $owner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $courses = $this->request('GET', '/admin/courses', $ownerBoot);
        self::assertSame(200, $courses->getStatusCode());
        $body = (string) $courses->getBody();
        self::assertStringContainsString('Published', $body);
        self::assertStringContainsString('Active batches', $body);
        self::assertStringContainsString('Learners enrolled', $body);
        self::assertStringContainsString($ownedTitle, $body);
        self::assertStringContainsString('January intake', $body);
        self::assertStringNotContainsString('Hidden metabolic course', $body);
        self::assertStringNotContainsString('later work package', $body);
        self::assertStringNotContainsString('Revenue', $body);
        self::assertStringNotContainsString('APP-PLAY-', $body);

        $edition = $this->request('GET', '/admin/courses/' . $owned['course_id'] . '/versions/' . $owned['version_id'], $ownerBoot);
        self::assertSame(200, $edition->getStatusCode());
        $editionBody = (string) $edition->getBody();
        self::assertStringContainsString('Publishing locks this edition', $editionBody);
        self::assertStringContainsString('Create the next edition', $editionBody);
        self::assertStringNotContainsString('CourseVersion', $editionBody);

        $chapters = $this->request(
            'GET',
            '/admin/courses/' . $owned['course_id'] . '/versions/' . $owned['version_id'] . '/curriculum',
            $ownerBoot,
        );
        self::assertStringContainsString('Chapters and lessons', (string) $chapters->getBody());
        self::assertStringContainsString('Chapter', (string) $chapters->getBody());
        self::assertStringNotContainsString('Add module', (string) $chapters->getBody());

        $faculty = $this->request('GET', '/faculty', $ownerBoot);
        self::assertSame(200, $faculty->getStatusCode());
        $facultyBody = (string) $faculty->getBody();
        self::assertStringContainsString('Your teaching', $facultyBody);
        self::assertStringContainsString('Clinic hour', $facultyBody);
        self::assertStringContainsString('IST', $facultyBody);
        self::assertStringContainsString('A learner was admitted', $facultyBody);
        self::assertStringContainsString($ownedTitle, $facultyBody);
        self::assertStringNotContainsString('Hidden metabolic course', $facultyBody);
        self::assertStringNotContainsString('meet.example.test', $facultyBody);
        self::assertStringNotContainsString('APP-PLAY-', $facultyBody);
        self::assertStringNotContainsString('Revenue', $facultyBody);

        $otherBoot = DatabaseTestCase::bindSessionForUser($other['user_id'], $other['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $otherFaculty = $this->request('GET', '/faculty', $otherBoot);
        self::assertStringNotContainsString($ownedTitle, (string) $otherFaculty->getBody());
        self::assertStringNotContainsString('Clinic hour', (string) $otherFaculty->getBody());

        $finance = DatabaseTestCase::financeFixture();
        $financeBoot = DatabaseTestCase::bindSessionForUser($finance['user_id'], $finance['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        self::assertSame(403, $this->request('GET', '/faculty', $financeBoot)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/admin/courses', $financeBoot)->getStatusCode());
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
     */
    private function request(string $method, string $path, array $boot): ResponseInterface
    {
        return ApplicationFactory::handle(
            (new ServerRequest([], [], 'http://localhost' . $path, $method))
                ->withCookieParams([
                    $this->sessionCookieName => $boot['session'],
                    $this->csrfCookieName => $boot['csrf'],
                ]),
        );
    }
}
