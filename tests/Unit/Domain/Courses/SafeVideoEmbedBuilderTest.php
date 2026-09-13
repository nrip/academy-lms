<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\SafeVideoEmbedBuilder;
use Academy\Domain\Courses\VideoDeliveryMode;
use Academy\Domain\Courses\VideoProvider;
use Academy\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class SafeVideoEmbedBuilderTest extends TestCase
{
    private SafeVideoEmbedBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SafeVideoEmbedBuilder();
    }

    public function testYoutubeWatchUrlBuildsEmbed(): void
    {
        $source = $this->builder->build(
            'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
            VideoDeliveryMode::EMBEDDED,
        );

        self::assertSame(VideoDeliveryMode::EMBEDDED, $source->deliveryMode);
        self::assertSame(VideoProvider::YOUTUBE, $source->provider);
        self::assertSame('https://www.youtube.com/embed/aqz-KE-bpKQ', $source->embedUrl);
        self::assertTrue($source->isEmbedded());
    }

    public function testYoutuBeAndNocookieAndVimeoAreDetected(): void
    {
        $short = $this->builder->build('https://youtu.be/aqz-KE-bpKQ', VideoDeliveryMode::EMBEDDED);
        self::assertSame(VideoProvider::YOUTUBE, $short->provider);
        self::assertSame('https://www.youtube.com/embed/aqz-KE-bpKQ', $short->embedUrl);

        $nocookie = $this->builder->build(
            'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ',
            VideoDeliveryMode::EMBEDDED,
        );
        self::assertSame(VideoProvider::YOUTUBE_NOCOOKIE, $nocookie->provider);
        self::assertSame('https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ', $nocookie->embedUrl);

        $vimeo = $this->builder->build('https://vimeo.com/148751763', VideoDeliveryMode::EMBEDDED);
        self::assertSame(VideoProvider::VIMEO, $vimeo->provider);
        self::assertSame('https://player.vimeo.com/video/148751763', $vimeo->embedUrl);
    }

    public function testExternalLinkAllowsHttpsNonEmbedProvider(): void
    {
        $source = $this->builder->build(
            'https://drive.google.com/file/d/abc123/view',
            VideoDeliveryMode::EXTERNAL_LINK,
        );

        self::assertSame(VideoDeliveryMode::EXTERNAL_LINK, $source->deliveryMode);
        self::assertSame(VideoProvider::EXTERNAL, $source->provider);
        self::assertNull($source->embedUrl);
        self::assertSame('https://drive.google.com/file/d/abc123/view', $source->sourceUrl);
    }

    public function testRejectsHttpUrls(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('HTTPS');
        $this->builder->build('http://www.youtube.com/watch?v=aqz-KE-bpKQ', VideoDeliveryMode::EMBEDDED);
    }

    public function testRejectsUnapprovedEmbeddedHost(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Embedded Player only supports');
        $this->builder->build(
            'https://drive.google.com/file/d/abc123/view',
            VideoDeliveryMode::EMBEDDED,
        );
    }

    public function testRejectsJavascriptUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->builder->build('javascript:alert(1)', VideoDeliveryMode::EXTERNAL_LINK);
    }

    public function testRejectsIframeHtmlAsUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->builder->build(
            '<iframe src="https://www.youtube.com/embed/aqz-KE-bpKQ"></iframe>',
            VideoDeliveryMode::EMBEDDED,
        );
    }
}
