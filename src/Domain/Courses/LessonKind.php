<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

/**
 * Customer-facing lesson labels. Storage modes stay on the server.
 */
final class LessonKind
{
    public const TEXT = 'text';
    public const RICH_TEXT = 'rich_text';
    public const PDF = 'pdf';
    public const VIDEO_EMBED = 'video_embed';
    public const VIDEO_LINK = 'video_link';
    public const VIDEO_UPLOAD = 'video_upload';
    public const PODCAST = 'podcast';
    public const AUDIO_UPLOAD = 'audio_upload';
    public const LIVE_SESSION = 'live_session';
    public const QUIZ = 'quiz';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::TEXT => 'Text',
            self::RICH_TEXT => 'Rich text',
            self::PDF => 'PDF',
            self::VIDEO_EMBED => 'Video embed',
            self::VIDEO_LINK => 'Video link',
            self::VIDEO_UPLOAD => 'Video upload',
            self::PODCAST => 'Podcast',
            self::AUDIO_UPLOAD => 'Audio upload',
            self::LIVE_SESSION => 'Live session',
            self::QUIZ => 'Quiz',
        ];
    }

    public static function labelForItem(ContentItem $item): string
    {
        $kind = self::fromItem($item);

        return self::labels()[$kind] ?? 'Lesson';
    }

    public static function fromItem(ContentItem $item): string
    {
        return match ($item->contentType) {
            ContentItemType::TEXT_LESSON => self::TEXT,
            ContentItemType::RICH_TEXT => self::RICH_TEXT,
            ContentItemType::PDF => self::PDF,
            ContentItemType::VIDEO => match ($item->videoDeliveryMode) {
                VideoDeliveryMode::EXTERNAL_LINK => self::VIDEO_LINK,
                VideoDeliveryMode::UPLOAD => self::VIDEO_UPLOAD,
                default => self::VIDEO_EMBED,
            },
            ContentItemType::PODCAST => self::PODCAST,
            ContentItemType::AUDIO => self::AUDIO_UPLOAD,
            ContentItemType::LIVE_SESSION => self::LIVE_SESSION,
            ContentItemType::MCQ_ASSESSMENT => self::QUIZ,
            default => self::TEXT,
        };
    }

    public static function fileKind(string $kind): ?string
    {
        return match ($kind) {
            self::PDF => 'pdf',
            self::VIDEO_UPLOAD => 'video',
            self::AUDIO_UPLOAD => 'audio',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function apply(array $input): array
    {
        $kind = trim((string) ($input['lesson_kind'] ?? ''));
        if ($kind === '' || !isset(self::labels()[$kind])) {
            return $input;
        }

        $input['content_type'] = match ($kind) {
            self::TEXT => ContentItemType::TEXT_LESSON,
            self::RICH_TEXT => ContentItemType::RICH_TEXT,
            self::PDF => ContentItemType::PDF,
            self::VIDEO_EMBED, self::VIDEO_LINK, self::VIDEO_UPLOAD => ContentItemType::VIDEO,
            self::PODCAST => ContentItemType::PODCAST,
            self::AUDIO_UPLOAD => ContentItemType::AUDIO,
            self::LIVE_SESSION => ContentItemType::LIVE_SESSION,
            self::QUIZ => ContentItemType::MCQ_ASSESSMENT,
            default => $input['content_type'] ?? '',
        };
        if ($kind === self::VIDEO_EMBED) {
            $input['video_delivery_mode'] = VideoDeliveryMode::EMBEDDED;
        } elseif ($kind === self::VIDEO_LINK) {
            $input['video_delivery_mode'] = VideoDeliveryMode::EXTERNAL_LINK;
        } elseif ($kind === self::VIDEO_UPLOAD) {
            $input['video_delivery_mode'] = VideoDeliveryMode::UPLOAD;
        }
        if ($kind === self::QUIZ) {
            $input['completion_rule'] = ContentCompletionRule::ASSESSMENT_PASSED;
        }

        return $input;
    }
}
