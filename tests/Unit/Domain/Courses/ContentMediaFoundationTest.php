<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\ContentItemDraftNormalizer;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\LearningMediaPolicy;
use Academy\Domain\Courses\LiveSessionProvider;
use Academy\Domain\Courses\PodcastUrlPolicy;
use Academy\Domain\Courses\RestrictedHtmlSanitiser;
use Academy\Domain\Courses\VideoDeliveryMode;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ContentMediaFoundationTest extends TestCase
{
    public function testLiveProviderIsDerivedFromHost(): void
    {
        self::assertSame(LiveSessionProvider::GOOGLE_MEET, LiveSessionProvider::fromJoinUrl('https://meet.google.com/abc-defg-hij'));
        self::assertSame(LiveSessionProvider::ZOOM, LiveSessionProvider::fromJoinUrl('https://us02web.zoom.us/j/123'));
        self::assertSame(LiveSessionProvider::TEAMS, LiveSessionProvider::fromJoinUrl('https://teams.microsoft.com/l/meetup-join/abc'));
        self::assertSame(LiveSessionProvider::CUSTOM, LiveSessionProvider::fromJoinUrl('https://learn.example.test/join/1'));
    }

    public function testPodcastDirectFileDetection(): void
    {
        $url = PodcastUrlPolicy::assertUrl('https://cdn.example.test/episode.mp3');
        self::assertTrue(PodcastUrlPolicy::isDirectAudioFile($url));
        self::assertFalse(PodcastUrlPolicy::isDirectAudioFile('https://podcasts.example.test/show'));
    }

    public function testRichTextKeepsAllowListedHttpsLinksOnly(): void
    {
        $html = (new RestrictedHtmlSanitiser())->sanitise(
            '<p>Read <a href="https://example.test/a" onclick="alert(1)">this</a></p><script>alert(1)</script>',
        );
        self::assertStringContainsString('href="https://example.test/a"', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('script', $html);
    }

    public function testPlayableSniffRejectsUnknownBytes(): void
    {
        $this->expectException(ValidationException::class);
        (new LearningMediaPolicy())->assertPlayable('video', 'not-a-video');
    }

    public function testPdfSignatureIsAccepted(): void
    {
        self::assertSame('application/pdf', (new LearningMediaPolicy())->assertPlayable('pdf', "%PDF-1.7\n"));
    }

    public function testUploadLimitsArePerKindAndConfigurationDriven(): void
    {
        $policy = new LearningMediaPolicy(pdfMaxBytes: 8, audioMaxBytes: 12, videoMaxBytes: 20);
        $pdf = "%PDF-1.7\nextra";
        self::assertGreaterThan(8, strlen($pdf));

        try {
            $policy->assertPlayable('pdf', $pdf);
            self::fail('Expected a PDF size rejection.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('PDF', $exception->getMessage());
            self::assertStringNotContainsString('100 MB', $exception->getMessage());
        }

        $video = str_repeat('x', 21);
        try {
            $policy->assertPlayable('video', $video);
            self::fail('Expected a video size rejection.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('video', $exception->getMessage());
            self::assertStringContainsString('upload limit', $exception->getMessage());
            self::assertStringNotContainsString('100 MB', $exception->getMessage());
        }

        self::assertSame('20', (new LearningMediaPolicy(videoMaxBytes: 20 * 1048576))->limitMegabytes('video'));
        self::assertStringContainsString('500 MB', (new LearningMediaPolicy())->uploadLimitMessage('video'));
        self::assertStringContainsString('100 MB', (new LearningMediaPolicy())->uploadLimitMessage('pdf'));
        self::assertStringContainsString('100 MB', (new LearningMediaPolicy())->uploadLimitMessage('audio'));
    }

    public function testDraftRequiresUtcLiveStartAndClearsVideoFields(): void
    {
        $fields = (new ContentItemDraftNormalizer())->normalize([
            'content_type' => ContentItemType::LIVE_SESSION,
            'title' => 'Office hours',
            'live_join_url' => 'https://meet.google.com/abc-defg-hij',
            'live_starts_at' => '2026-10-01T09:00:00Z',
        ]);

        self::assertSame(LiveSessionProvider::GOOGLE_MEET, $fields['live_provider']);
        self::assertNull($fields['video_url']);
        self::assertNull($fields['object_key']);
        self::assertSame('2026-10-01 09:00:00', $fields['live_starts_at']?->format('Y-m-d H:i:s'));
    }

    public function testVideoUploadKeepsObjectKeyAndDropsUrl(): void
    {
        $fields = (new ContentItemDraftNormalizer())->normalize([
            'content_type' => ContentItemType::VIDEO,
            'title' => 'Lecture',
            'video_delivery_mode' => VideoDeliveryMode::UPLOAD,
            'object_key' => 'learning/media/abc',
            'media_mime' => 'video/mp4',
            'video_url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
        ]);

        self::assertSame(VideoDeliveryMode::UPLOAD, $fields['video_delivery_mode']);
        self::assertSame('learning/media/abc', $fields['object_key']);
        self::assertNull($fields['video_url']);
        self::assertNull($fields['video_provider']);
    }
}
