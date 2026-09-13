<?php

declare(strict_types=1);

namespace Academy\Tests\Http;

use Academy\Domain\Identity\AuthStage;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Tests\Support\ApplicationFactory;
use Academy\Tests\Support\DatabaseTestCase;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class VideoContentHttpTest extends TestCase
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

    public function testAdminCanCreateEmbeddedAndExternalVideoAndRejectUnsafeUrls(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);

        [$courseId, $versionId, $moduleId] = $this->createDraftCourseWithModule($boot);

        $embedded = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'video',
                'title' => 'Intro video',
                'body_text' => 'Public YouTube overview',
                'video_url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
                'video_delivery_mode' => 'embedded',
            ],
        );
        self::assertSame(303, $embedded->getStatusCode());

        $external = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'video',
                'title' => 'External portal clip',
                'body_text' => 'Opens outside the LMS',
                'video_url' => 'https://example.com/videos/metabolic-health',
                'video_delivery_mode' => 'external_link',
            ],
        );
        self::assertSame(303, $external->getStatusCode());

        $text = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'text_lesson',
                'title' => 'Reading Material',
                'body_text' => 'Still works alongside video.',
            ],
        );
        self::assertSame(303, $text->getStatusCode());

        $unsafeEmbedded = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'video',
                'title' => 'Bad embed',
                'video_url' => 'https://drive.google.com/file/d/abc/view',
                'video_delivery_mode' => 'embedded',
            ],
        );
        self::assertSame(422, $unsafeEmbedded->getStatusCode());
        self::assertStringContainsString('Embedded Player only supports', (string) $unsafeEmbedded->getBody());

        $httpUrl = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $boot,
            [
                'content_type' => 'video',
                'title' => 'HTTP rejected',
                'video_url' => 'http://example.com/video.mp4',
                'video_delivery_mode' => 'external_link',
            ],
        );
        self::assertSame(422, $httpUrl->getStatusCode());
        self::assertStringContainsString('HTTPS', (string) $httpUrl->getBody());

        $pdo = DatabaseTestCase::pdo();
        $rows = $pdo->prepare(
            'SELECT content_type, title, video_provider, video_delivery_mode FROM content_items
             WHERE module_id = :m ORDER BY sequence',
        );
        $rows->execute(['m' => $moduleId]);
        $items = $rows->fetchAll();
        self::assertCount(3, $items);
        self::assertSame('video', $items[0]['content_type']);
        self::assertSame('youtube', $items[0]['video_provider']);
        self::assertSame('embedded', $items[0]['video_delivery_mode']);
        self::assertSame('external', $items[1]['video_provider']);
        self::assertSame('text_lesson', $items[2]['content_type']);
    }

    public function testLearnerSeesEmbedOrWatchButtonAndCanMarkComplete(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $bootAdmin = DatabaseTestCase::bindSessionForUser(
            $admin['user_id'],
            $admin['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );
        [$courseId, $versionId, $moduleId] = $this->createDraftCourseWithModule($bootAdmin);

        $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $bootAdmin,
            [
                'content_type' => 'video',
                'title' => 'Introduction to Metabolic Health',
                'body_text' => 'Watch this overview, then mark complete.',
                'video_url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
                'video_delivery_mode' => 'embedded',
            ],
        );
        $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content',
            $bootAdmin,
            [
                'content_type' => 'video',
                'title' => 'External lecture link',
                'body_text' => 'Opens in a new tab.',
                'video_url' => 'https://example.com/secure/lecture',
                'video_delivery_mode' => 'external_link',
            ],
        );

        $pdo = DatabaseTestCase::pdo();
        $contentIds = $pdo->prepare(
            'SELECT content_id FROM content_items WHERE module_id = :m ORDER BY sequence',
        );
        $contentIds->execute(['m' => $moduleId]);
        $ids = array_map('intval', $contentIds->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(2, $ids);

        $this->publishVersion($courseId, $versionId);

        $learner = DatabaseTestCase::applicantFixture();
        $enrolment = DatabaseTestCase::seedActiveEnrolment($learner['user_id'], $courseId, $versionId);
        $enrolmentId = $enrolment['enrolment_id'];
        $boot = DatabaseTestCase::bindSessionForUser(
            $learner['user_id'],
            $learner['auth_version'],
            AuthStage::FULLY_AUTHENTICATED,
        );

        $embeddedPage = $this->request(
            'GET',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $ids[0],
            $boot,
        );
        self::assertSame(200, $embeddedPage->getStatusCode());
        $embeddedHtml = (string) $embeddedPage->getBody();
        self::assertStringContainsString('Introduction to Metabolic Health', $embeddedHtml);
        self::assertStringContainsString('https://www.youtube.com/embed/aqz-KE-bpKQ', $embeddedHtml);
        self::assertStringContainsString('<iframe', $embeddedHtml);
        self::assertStringContainsString('Mark complete', $embeddedHtml);

        $complete = $this->request(
            'POST',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $ids[0] . '/complete',
            $boot,
        );
        self::assertSame(303, $complete->getStatusCode());

        $status = $pdo->prepare(
            'SELECT completion_status FROM content_progress WHERE enrolment_id = :e AND content_id = :c',
        );
        $status->execute(['e' => $enrolmentId, 'c' => $ids[0]]);
        self::assertSame(ContentProgressCompletionStatus::COMPLETED, $status->fetchColumn());

        $externalPage = $this->request(
            'GET',
            '/learning/enrolments/' . $enrolmentId . '/items/' . $ids[1],
            $boot,
        );
        self::assertSame(200, $externalPage->getStatusCode());
        $externalHtml = (string) $externalPage->getBody();
        self::assertStringContainsString('Watch Video', $externalHtml);
        self::assertStringContainsString('https://example.com/secure/lecture', $externalHtml);
        self::assertStringNotContainsString('<iframe', $externalHtml);
    }

    public function testAdminCanPersistNewLessonMetadataWithoutUiChanges(): void
    {
        $admin = DatabaseTestCase::courseAdminFixture();
        $boot = DatabaseTestCase::bindSessionForUser($admin['user_id'], $admin['auth_version'], AuthStage::FULLY_AUTHENTICATED);
        [$courseId, $versionId, $moduleId] = $this->createDraftCourseWithModule($boot);
        $path = '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules/' . $moduleId . '/content';

        self::assertSame(303, $this->request('POST', $path, $boot, [
            'content_type' => 'live_session',
            'title' => 'Clinic hour',
            'live_join_url' => 'https://meet.google.com/abc-defg-hij',
            'live_starts_at' => '2026-10-01T09:00:00Z',
        ])->getStatusCode());

        self::assertSame(303, $this->request('POST', $path, $boot, [
            'content_type' => 'podcast',
            'title' => 'Episode',
            'podcast_url' => 'https://cdn.example.test/ep1.mp3',
        ])->getStatusCode());

        self::assertSame(303, $this->request('POST', $path, $boot, [
            'content_type' => 'video',
            'title' => 'Uploaded lecture',
            'video_delivery_mode' => 'upload',
            'object_key' => 'learning/media/lecture1',
            'media_mime' => 'video/mp4',
            'original_filename' => 'lecture.mp4',
        ])->getStatusCode());

        $rows = DatabaseTestCase::pdo()->prepare(
            'SELECT content_type, live_provider, podcast_url, video_delivery_mode, object_key, media_mime
             FROM content_items WHERE module_id = :m ORDER BY sequence',
        );
        $rows->execute(['m' => $moduleId]);
        $items = $rows->fetchAll();
        self::assertSame('live_session', $items[0]['content_type']);
        self::assertSame('google_meet', $items[0]['live_provider']);
        self::assertSame('https://cdn.example.test/ep1.mp3', $items[1]['podcast_url']);
        self::assertSame('upload', $items[2]['video_delivery_mode']);
        self::assertSame('learning/media/lecture1', $items[2]['object_key']);
        self::assertSame('video/mp4', $items[2]['media_mime']);
    }

    /**
     * @param array{session: string, csrf: string} $boot
     * @return array{0: int, 1: int, 2: int}
     */
    private function createDraftCourseWithModule(array $boot): array
    {
        $suffix = bin2hex(random_bytes(3));
        $create = $this->request('POST', '/admin/courses', $boot, [
            'course_code' => 'VID-' . strtoupper($suffix),
            'slug' => 'video-' . $suffix,
            'master_title' => 'Video Content Course ' . $suffix,
        ]);
        self::assertSame(303, $create->getStatusCode());
        preg_match('#^/admin/courses/(\d+)/versions/(\d+)$#', $create->getHeaderLine('Location'), $m);
        $courseId = (int) $m[1];
        $versionId = (int) $m[2];

        $module = $this->request(
            'POST',
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '/modules',
            $boot,
            [
                'title' => 'Module 1',
                'description' => 'Video foundations',
                'release_rule' => 'immediate',
                'mandatory_flag' => '1',
            ],
        );
        self::assertSame(303, $module->getStatusCode());

        $pdo = DatabaseTestCase::pdo();
        $moduleId = (int) $pdo->query(
            'SELECT module_id FROM modules WHERE course_version_id = ' . $versionId . ' ORDER BY sequence LIMIT 1',
        )->fetchColumn();

        return [$courseId, $versionId, $moduleId];
    }

    private function publishVersion(int $courseId, int $versionId): void
    {
        $pdo = DatabaseTestCase::pdo();
        $now = gmdate('Y-m-d H:i:s.u');
        $pdo->prepare(
            'UPDATE course_versions SET
                status = :status,
                published_at = :published_at,
                locked_at = :locked_at,
                locked_reason = :locked_reason,
                description = :description,
                learning_objectives = :learning_objectives,
                intended_audience = :intended_audience,
                syllabus_summary = :syllabus_summary,
                duration_text = :duration_text,
                standard_fee = :standard_fee,
                updated_at = :updated_at
             WHERE version_id = :version_id',
        )->execute([
            'status' => 'published',
            'published_at' => $now,
            'locked_at' => $now,
            'locked_reason' => 'published',
            'description' => 'Published video demo course for automated tests.',
            'learning_objectives' => 'Complete video lessons via mark-complete.',
            'intended_audience' => 'Automated test learners.',
            'syllabus_summary' => 'Video lessons only.',
            'duration_text' => 'Self-paced',
            'standard_fee' => '1000.00',
            'updated_at' => $now,
            'version_id' => $versionId,
        ]);
        $pdo->prepare(
            'UPDATE courses SET current_published_version_id = :version_id, updated_at = :updated_at
             WHERE course_id = :course_id',
        )->execute([
            'version_id' => $versionId,
            'updated_at' => $now,
            'course_id' => $courseId,
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
