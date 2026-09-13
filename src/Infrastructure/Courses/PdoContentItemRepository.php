<?php

declare(strict_types=1);

namespace Academy\Infrastructure\Courses;

use Academy\Domain\Courses\ContentDelivery;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemContext;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoContentItemRepository implements ContentItemRepository
{
    private const COLUMNS = 'content_id, module_id, sequence, content_type, title, body_text, object_key,
        video_url, video_delivery_mode, video_provider,
        original_filename, media_mime, media_bytes, media_sha256, podcast_url,
        live_join_url, live_starts_at, live_ends_at, live_provider, live_recording_url, live_external_meeting_id,
        mandatory_flag, completion_rule, created_at, updated_at';

    public function __construct(
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function findById(int $contentId): ?ContentItem
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM content_items WHERE content_id = :id LIMIT 1');
        $stmt->execute(['id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findContextById(int $contentId): ?ContentItemContext
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ci.content_id, ci.module_id, ci.sequence, ci.content_type, ci.title, ci.body_text,
                    ci.object_key, ci.video_url, ci.video_delivery_mode, ci.video_provider,
                    ci.original_filename, ci.media_mime, ci.media_bytes, ci.media_sha256, ci.podcast_url,
                    ci.live_join_url, ci.live_starts_at, ci.live_ends_at, ci.live_provider,
                    ci.live_recording_url, ci.live_external_meeting_id,
                    ci.mandatory_flag, ci.completion_rule, ci.created_at, ci.updated_at,
                    m.course_version_id, cv.course_id, cv.locked_at
             FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             INNER JOIN course_versions cv ON cv.version_id = m.course_version_id
             WHERE ci.content_id = :id
             LIMIT 1',
        );
        $stmt->execute(['id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new ContentItemContext(
            contentItem: $this->mapRow($row),
            courseId: (int) $row['course_id'],
            courseVersionId: (int) $row['course_version_id'],
            versionLocked: $row['locked_at'] !== null,
        );
    }

    public function listByModuleId(int $moduleId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM content_items
             WHERE module_id = :module_id
             ORDER BY sequence ASC, content_id ASC',
        );
        $stmt->execute(['module_id' => $moduleId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    public function listByCourseVersionId(int $courseVersionId): array
    {
        $pdo = $this->connections->connection();
        $columns = implode(', ', array_map(
            static fn (string $column): string => 'ci.' . trim($column),
            explode(',', str_replace("\n", '', self::COLUMNS)),
        ));
        $stmt = $pdo->prepare(
            'SELECT ' . $columns . '
             FROM content_items ci
             INNER JOIN modules m ON m.module_id = ci.module_id
             WHERE m.course_version_id = :version_id
             ORDER BY m.sequence ASC, ci.sequence ASC, ci.content_id ASC',
        );
        $stmt->execute(['version_id' => $courseVersionId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    public function nextSequence(int $moduleId): int
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM content_items WHERE module_id = :module_id',
        );
        $stmt->execute(['module_id' => $moduleId]);

        return (int) $stmt->fetchColumn();
    }

    public function insert(array $data): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO content_items (
                module_id, sequence, content_type, title, body_text, object_key,
                video_url, video_delivery_mode, video_provider,
                original_filename, media_mime, media_bytes, media_sha256, podcast_url,
                live_join_url, live_starts_at, live_ends_at, live_provider, live_recording_url, live_external_meeting_id,
                mandatory_flag, completion_rule, created_at, updated_at
             ) VALUES (
                :module_id, :sequence, :content_type, :title, :body_text, :object_key,
                :video_url, :video_delivery_mode, :video_provider,
                :original_filename, :media_mime, :media_bytes, :media_sha256, :podcast_url,
                :live_join_url, :live_starts_at, :live_ends_at, :live_provider, :live_recording_url, :live_external_meeting_id,
                :mandatory_flag, :completion_rule, :created_at, :updated_at
             )',
        );
        $stmt->execute($this->writeBindings($data) + [
            'module_id' => $data['module_id'],
            'sequence' => $data['sequence'],
            'content_type' => $data['content_type'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function update(int $contentId, array $data): bool
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'UPDATE content_items SET
                title = :title,
                body_text = :body_text,
                object_key = :object_key,
                video_url = :video_url,
                video_delivery_mode = :video_delivery_mode,
                video_provider = :video_provider,
                original_filename = :original_filename,
                media_mime = :media_mime,
                media_bytes = :media_bytes,
                media_sha256 = :media_sha256,
                podcast_url = :podcast_url,
                live_join_url = :live_join_url,
                live_starts_at = :live_starts_at,
                live_ends_at = :live_ends_at,
                live_provider = :live_provider,
                live_recording_url = :live_recording_url,
                live_external_meeting_id = :live_external_meeting_id,
                mandatory_flag = :mandatory_flag,
                completion_rule = :completion_rule,
                updated_at = :updated_at
             WHERE content_id = :content_id',
        );
        $stmt->execute($this->writeBindings($data) + [
            'updated_at' => $now,
            'content_id' => $contentId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $contentId): bool
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('DELETE FROM content_items WHERE content_id = :content_id');
        $stmt->execute(['content_id' => $contentId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ContentItem
    {
        $utc = new DateTimeZone('UTC');

        return new ContentItem(
            contentId: (int) $row['content_id'],
            moduleId: (int) $row['module_id'],
            sequence: (int) $row['sequence'],
            contentType: (string) $row['content_type'],
            title: (string) $row['title'],
            bodyText: $row['body_text'] === null ? null : (string) $row['body_text'],
            objectKey: $row['object_key'] === null ? null : (string) $row['object_key'],
            videoUrl: $row['video_url'] === null ? null : (string) $row['video_url'],
            videoDeliveryMode: $row['video_delivery_mode'] === null ? null : (string) $row['video_delivery_mode'],
            videoProvider: $row['video_provider'] === null ? null : (string) $row['video_provider'],
            mandatoryFlag: (int) $row['mandatory_flag'] === 1,
            completionRule: (string) $row['completion_rule'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt: new DateTimeImmutable((string) $row['updated_at'], $utc),
            delivery: new ContentDelivery(
                originalFilename: $this->nullableString($row, 'original_filename'),
                mediaMime: $this->nullableString($row, 'media_mime'),
                mediaBytes: isset($row['media_bytes']) && $row['media_bytes'] !== null ? (int) $row['media_bytes'] : null,
                mediaSha256: $this->nullableString($row, 'media_sha256'),
                podcastUrl: $this->nullableString($row, 'podcast_url'),
                liveJoinUrl: $this->nullableString($row, 'live_join_url'),
                liveStartsAt: $this->nullableUtc($row, 'live_starts_at', $utc),
                liveEndsAt: $this->nullableUtc($row, 'live_ends_at', $utc),
                liveProvider: $this->nullableString($row, 'live_provider'),
                liveRecordingUrl: $this->nullableString($row, 'live_recording_url'),
                liveExternalMeetingId: $this->nullableString($row, 'live_external_meeting_id'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function writeBindings(array $data): array
    {
        return [
            'title' => $data['title'],
            'body_text' => $data['body_text'],
            'object_key' => $data['object_key'],
            'video_url' => $data['video_url'],
            'video_delivery_mode' => $data['video_delivery_mode'],
            'video_provider' => $data['video_provider'],
            'original_filename' => $data['original_filename'] ?? null,
            'media_mime' => $data['media_mime'] ?? null,
            'media_bytes' => $data['media_bytes'] ?? null,
            'media_sha256' => $data['media_sha256'] ?? null,
            'podcast_url' => $data['podcast_url'] ?? null,
            'live_join_url' => $data['live_join_url'] ?? null,
            'live_starts_at' => $this->formatUtc($data['live_starts_at'] ?? null),
            'live_ends_at' => $this->formatUtc($data['live_ends_at'] ?? null),
            'live_provider' => $data['live_provider'] ?? null,
            'live_recording_url' => $data['live_recording_url'] ?? null,
            'live_external_meeting_id' => $data['live_external_meeting_id'] ?? null,
            'mandatory_flag' => $data['mandatory_flag'] ? 1 : 0,
            'completion_rule' => $data['completion_rule'],
        ];
    }

    private function formatUtc(mixed $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return (string) $row[$key];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableUtc(array $row, string $key, DateTimeZone $utc): ?DateTimeImmutable
    {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            return null;
        }

        return new DateTimeImmutable((string) $row[$key], $utc);
    }
}
