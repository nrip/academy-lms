<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Normalises a curriculum draft into content_items columns.
 * Does not render the builder UI.
 */
final class ContentItemDraftNormalizer
{
    public function __construct(
        private readonly SafeVideoEmbedBuilder $videoEmbeds = new SafeVideoEmbedBuilder(),
        private readonly RestrictedHtmlSanitiser $html = new RestrictedHtmlSanitiser(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   content_type: string,
     *   title: string,
     *   body_text: ?string,
     *   object_key: ?string,
     *   video_url: ?string,
     *   video_delivery_mode: ?string,
     *   video_provider: ?string,
     *   mandatory_flag: bool,
     *   completion_rule: string,
     *   original_filename: ?string,
     *   media_mime: ?string,
     *   media_bytes: ?int,
     *   media_sha256: ?string,
     *   podcast_url: ?string,
     *   live_join_url: ?string,
     *   live_starts_at: ?DateTimeImmutable,
     *   live_ends_at: ?DateTimeImmutable,
     *   live_provider: ?string,
     *   live_recording_url: ?string,
     *   live_external_meeting_id: ?string
     * }
     */
    public function normalize(array $input): array
    {
        $type = ContentItemType::assertCreatable(trim((string) ($input['content_type'] ?? '')));
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('Content title is required.');
        }
        if (mb_strlen($title) > 255) {
            throw new ValidationException('Content title must be 255 characters or fewer.');
        }

        $bodyText = null;
        $objectKey = null;
        $videoUrl = null;
        $videoDeliveryMode = null;
        $videoProvider = null;
        $filename = null;
        $mime = null;
        $bytes = null;
        $sha256 = null;
        $podcastUrl = null;
        $liveJoinUrl = null;
        $liveStartsAt = null;
        $liveEndsAt = null;
        $liveProvider = null;
        $liveRecordingUrl = null;
        $liveExternalMeetingId = null;

        if ($type === ContentItemType::TEXT_LESSON) {
            $bodyText = $this->requiredText((string) ($input['body_text'] ?? ''), 'Text lesson body is required.');
        } elseif ($type === ContentItemType::RICH_TEXT) {
            $bodyText = $this->html->sanitise((string) ($input['body_text'] ?? ''));
        } elseif ($type === ContentItemType::PDF) {
            $objectKey = $this->requiredObjectKey($input);
            [$filename, $mime, $bytes, $sha256] = $this->optionalFileMeta($input);
        } elseif ($type === ContentItemType::MCQ_ASSESSMENT) {
            // Assessment body is stored on the assessment, not the content item.
        } elseif ($type === ContentItemType::VIDEO) {
            $delivery = trim((string) ($input['video_delivery_mode'] ?? ''));
            if ($delivery === VideoDeliveryMode::UPLOAD) {
                $videoDeliveryMode = VideoDeliveryMode::UPLOAD;
                $objectKey = $this->requiredObjectKey($input);
                [$filename, $mime, $bytes, $sha256] = $this->optionalFileMeta($input, true);
                if (!in_array($mime, ['video/mp4', 'video/webm'], true)) {
                    throw new ValidationException('Uploaded video must be MP4 or WebM. Transcoding is not available.');
                }
            } else {
                $source = $this->videoEmbeds->build(trim((string) ($input['video_url'] ?? '')), $delivery);
                $videoUrl = $source->sourceUrl;
                $videoDeliveryMode = $source->deliveryMode;
                $videoProvider = $source->provider;
            }
        } elseif ($type === ContentItemType::PODCAST) {
            $podcastUrl = PodcastUrlPolicy::assertUrl((string) ($input['podcast_url'] ?? ''));
            if (strlen($podcastUrl) > 2048) {
                throw new ValidationException('Podcast URL must be 2048 characters or fewer.');
            }
        } elseif ($type === ContentItemType::AUDIO) {
            $objectKey = $this->requiredObjectKey($input);
            [$filename, $mime, $bytes, $sha256] = $this->optionalFileMeta($input, true);
            if (!in_array($mime, ['audio/mpeg', 'audio/mp4', 'audio/wav'], true)) {
                throw new ValidationException('Uploaded audio must be MP3, M4A, or WAV. Transcoding is not available.');
            }
        } elseif ($type === ContentItemType::LIVE_SESSION) {
            $liveJoinUrl = LiveSessionProvider::assertHttps(
                (string) ($input['live_join_url'] ?? ''),
                'Live session join URL must be an HTTPS link.',
            );
            if (strlen($liveJoinUrl) > 2048) {
                throw new ValidationException('Live session join URL must be 2048 characters or fewer.');
            }
            $liveProvider = LiveSessionProvider::fromJoinUrl($liveJoinUrl);
            $liveStartsAt = $this->requiredUtc((string) ($input['live_starts_at'] ?? ''), 'Live session start');
            $endRaw = trim((string) ($input['live_ends_at'] ?? ''));
            if ($endRaw !== '') {
                $liveEndsAt = $this->requiredUtc($endRaw, 'Live session end');
                if ($liveEndsAt <= $liveStartsAt) {
                    throw new ValidationException('Live session end must be after the start.');
                }
            }
            $recording = trim((string) ($input['live_recording_url'] ?? ''));
            if ($recording !== '') {
                $liveRecordingUrl = LiveSessionProvider::assertHttps(
                    $recording,
                    'Live session recording URL must be an HTTPS link.',
                );
            }
            $meetingId = trim((string) ($input['live_external_meeting_id'] ?? ''));
            if ($meetingId !== '') {
                if (mb_strlen($meetingId) > 128) {
                    throw new ValidationException('Live session meeting id must be 128 characters or fewer.');
                }
                $liveExternalMeetingId = $meetingId;
            }
        }

        $mandatory = !array_key_exists('mandatory_flag', $input)
            ? true
            : (
                (string) $input['mandatory_flag'] === '1'
                || $input['mandatory_flag'] === true
                || $input['mandatory_flag'] === 1
            );

        $completion = ContentCompletionRule::assertValid(
            trim((string) ($input['completion_rule'] ?? ContentCompletionRule::defaultForType($type))),
        );
        if ($type !== ContentItemType::MCQ_ASSESSMENT && $completion === ContentCompletionRule::ASSESSMENT_PASSED) {
            throw new ValidationException('assessment_passed is only valid for MCQ assessment content.');
        }

        return [
            'content_type' => $type,
            'title' => $title,
            'body_text' => $bodyText,
            'object_key' => $objectKey,
            'video_url' => $videoUrl,
            'video_delivery_mode' => $videoDeliveryMode,
            'video_provider' => $videoProvider,
            'mandatory_flag' => $mandatory,
            'completion_rule' => $completion,
            'original_filename' => $filename,
            'media_mime' => $mime,
            'media_bytes' => $bytes,
            'media_sha256' => $sha256,
            'podcast_url' => $podcastUrl,
            'live_join_url' => $liveJoinUrl,
            'live_starts_at' => $liveStartsAt,
            'live_ends_at' => $liveEndsAt,
            'live_provider' => $liveProvider,
            'live_recording_url' => $liveRecordingUrl,
            'live_external_meeting_id' => $liveExternalMeetingId,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredObjectKey(array $input): string
    {
        $objectKey = trim((string) ($input['object_key'] ?? ''));
        if ($objectKey === '') {
            throw new ValidationException('This lesson requires a private media object key.');
        }
        if (strlen($objectKey) > 512 || str_contains($objectKey, '..') || str_starts_with($objectKey, '/')) {
            throw new ValidationException('Object key is invalid.');
        }

        return $objectKey;
    }

    private function requiredText(string $body, string $message): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            throw new ValidationException($message);
        }

        return $trimmed;
    }

    private function requiredUtc(string $value, string $label): DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ValidationException($label . ' is required and must be an ISO-8601 UTC timestamp.');
        }
        try {
            $parsed = new DateTimeImmutable($trimmed);
        } catch (\Exception) {
            throw new ValidationException($label . ' must be an ISO-8601 UTC timestamp.');
        }
        if (!preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $trimmed)) {
            throw new ValidationException($label . ' must include a UTC offset or Z.');
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?string}
     */
    private function optionalFileMeta(array $input, bool $mimeRequired = false): array
    {
        $policy = new LearningMediaPolicy();
        $filename = $policy->displayFilename(
            isset($input['original_filename']) ? (string) $input['original_filename'] : null,
        );
        $mime = trim((string) ($input['media_mime'] ?? ''));
        $mime = $mime === '' ? null : $mime;
        if ($mimeRequired && $mime === null) {
            throw new ValidationException('Detected media type is required for an uploaded file.');
        }
        if ($mime !== null && strlen($mime) > 128) {
            throw new ValidationException('Media type is invalid.');
        }
        $bytes = $input['media_bytes'] ?? null;
        $bytesValue = $bytes === null || $bytes === '' ? null : (int) $bytes;
        if ($bytesValue !== null && $bytesValue < 0) {
            throw new ValidationException('Media size is invalid.');
        }
        $sha = strtolower(trim((string) ($input['media_sha256'] ?? '')));
        $sha = $sha === '' ? null : $sha;
        if ($sha !== null && preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new ValidationException('Media checksum is invalid.');
        }

        return [$filename, $mime, $bytesValue, $sha];
    }
}
