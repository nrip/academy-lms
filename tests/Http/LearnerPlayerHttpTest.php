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

    public function testOutlineAndPlayerUseLessonLanguageForLiveAndPodcast(): void
    {
        $learner = DatabaseTestCase::applicantFixture();
        $boot = DatabaseTestCase::bindSessionForUser($learner['user_id'], $learner['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        $course = DatabaseTestCase::seedPublishedCourseWithCurriculum(['Clinic hour', 'Episode']);
        $pdo = DatabaseTestCase::pdo();
        $now = gmdate('Y-m-d H:i:s.u');
        $pdo->prepare('UPDATE course_versions SET locked_at = NULL, locked_reason = NULL WHERE version_id = :id')
            ->execute(['id' => $course['version_id']]);
        $pdo->prepare(
            'UPDATE content_items
             SET content_type = :type, body_text = NULL, live_join_url = :join, live_starts_at = :starts,
                 live_provider = :provider, live_recording_url = :recording, updated_at = :updated
             WHERE content_id = :id',
        )->execute([
            'type' => 'live_session',
            'join' => 'https://meet.google.com/abc-defg-hij',
            'starts' => '2026-10-01 09:00:00.000000',
            'provider' => 'google_meet',
            'recording' => 'https://example.com/recording',
            'updated' => $now,
            'id' => $course['content_ids'][0],
        ]);
        $moduleStmt = $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag, release_rule,
                prerequisite_module_id, created_at, updated_at
             ) VALUES (
                :version_id, 2, :title, :description, 1, :release_rule, NULL, :created_at, :updated_at
             )',
        );
        $moduleStmt->execute([
            'version_id' => $course['version_id'],
            'title' => 'Audio module',
            'description' => 'Listen',
            'release_rule' => 'immediate',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $podcastModuleId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, podcast_url,
                mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, 1, :type, :title, NULL, :podcast_url, 1, :rule, :created_at, :updated_at
             )',
        )->execute([
            'module_id' => $podcastModuleId,
            'type' => 'podcast',
            'title' => 'Morning round',
            'podcast_url' => 'https://cdn.example.test/rounds/ep1.mp3',
            'rule' => 'mark_complete',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $podcastId = (int) $pdo->lastInsertId();
        $videoModule = $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag, release_rule,
                prerequisite_module_id, created_at, updated_at
             ) VALUES (
                :version_id, 3, :title, :description, 1, :release_rule, NULL, :created_at, :updated_at
             )',
        );
        $videoModule->execute([
            'version_id' => $course['version_id'],
            'title' => 'Video module',
            'description' => 'Watch',
            'release_rule' => 'immediate',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $videoModuleId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, video_delivery_mode, object_key,
                media_mime, original_filename, mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, 1, :type, :title, :mode, :object_key, :mime, :filename,
                1, :rule, :created_at, :updated_at
             )',
        )->execute([
            'module_id' => $videoModuleId,
            'type' => 'video',
            'title' => 'Uploaded lecture',
            'mode' => 'upload',
            'object_key' => 'learning/media/lecture1.mp4',
            'mime' => 'video/mp4',
            'filename' => 'lecture.mp4',
            'rule' => 'mark_complete',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $videoId = (int) $pdo->lastInsertId();
        $pdfModule = $pdo->prepare(
            'INSERT INTO modules (
                course_version_id, sequence, title, description, mandatory_flag, release_rule,
                prerequisite_module_id, created_at, updated_at
             ) VALUES (
                :version_id, 4, :title, :description, 1, :release_rule, NULL, :created_at, :updated_at
             )',
        );
        $pdfModule->execute([
            'version_id' => $course['version_id'],
            'title' => 'Reading module',
            'description' => 'Read',
            'release_rule' => 'immediate',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pdfModuleId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, object_key, media_mime, original_filename,
                mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, 1, :type, :title, :object_key, :mime, :filename, 1, :rule, :created_at, :updated_at
             )',
        )->execute([
            'module_id' => $pdfModuleId,
            'type' => 'pdf',
            'title' => 'Handout',
            'object_key' => 'learning/media/handout.pdf',
            'mime' => 'application/pdf',
            'filename' => 'handout.pdf',
            'rule' => 'mark_complete',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $pdfId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE course_versions SET locked_at = :locked, locked_reason = :reason WHERE version_id = :id',
        )->execute([
            'locked' => $now,
            'reason' => 'published',
            'id' => $course['version_id'],
        ]);

        $enrolment = DatabaseTestCase::seedActiveEnrolment(
            $learner['user_id'],
            $course['course_id'],
            $course['version_id'],
        );
        $enrolmentId = $enrolment['enrolment_id'];

        $outline = $this->request('GET', '/learning/enrolments/' . $enrolmentId, $boot);
        self::assertSame(200, $outline->getStatusCode());
        $outlineHtml = (string) $outline->getBody();
        self::assertStringContainsString('Live session', $outlineHtml);
        self::assertStringContainsString('Podcast', $outlineHtml);
        self::assertStringContainsString('Continue', $outlineHtml);
        self::assertStringNotContainsString('live_session', $outlineHtml);

        $live = $this->request(
            'GET',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $course['content_ids'][0],
            $boot,
        );
        self::assertSame(200, $live->getStatusCode());
        $liveHtml = (string) $live->getBody();
        self::assertStringContainsString('Join', $liveHtml);
        self::assertStringContainsString('https://meet.google.com/abc-defg-hij', $liveHtml);
        self::assertStringContainsString('Recording', $liveHtml);
        self::assertStringContainsString('I attended', $liveHtml);
        self::assertStringContainsString('Google Meet', $liveHtml);
        self::assertStringContainsString('IST', $liveHtml);
        self::assertStringNotContainsString('<iframe', $liveHtml);

        $podcast = $this->request(
            'GET',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $podcastId,
            $boot,
        );
        self::assertSame(200, $podcast->getStatusCode());
        $podcastHtml = (string) $podcast->getBody();
        self::assertStringContainsString('<audio', $podcastHtml);
        self::assertStringContainsString('https://cdn.example.test/rounds/ep1.mp3', $podcastHtml);
        self::assertStringContainsString('Listen', $podcastHtml);
        self::assertStringContainsString('https://cdn.example.test', $podcast->getHeaderLine('Content-Security-Policy'));
        self::assertSame('', $podcast->getHeaderLine('X-Academy-Media-Src'));

        $video = $this->request(
            'GET',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $videoId,
            $boot,
        );
        self::assertSame(200, $video->getStatusCode());
        $videoHtml = (string) $video->getBody();
        self::assertStringContainsString('<video', $videoHtml);
        self::assertStringContainsString('/learning/enrolments/' . $enrolmentId . '/items/' . $videoId . '/media', $videoHtml);
        self::assertStringNotContainsString('learning/media/lecture1.mp4', $videoHtml);

        $pdf = $this->request(
            'GET',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $pdfId,
            $boot,
        );
        self::assertSame(200, $pdf->getStatusCode());
        $pdfHtml = (string) $pdf->getBody();
        self::assertStringContainsString('Download', $pdfHtml);
        self::assertStringContainsString('data-acad-pdf-viewer', $pdfHtml);
        self::assertStringContainsString('/learning/enrolments/' . $enrolmentId . '/items/' . $pdfId . '/media/file', $pdfHtml);
        self::assertStringContainsString('handout.pdf', $pdfHtml);
        self::assertStringNotContainsString('learning/media/handout.pdf', $pdfHtml);
        self::assertStringNotContainsString('annotation', $pdfHtml);
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
