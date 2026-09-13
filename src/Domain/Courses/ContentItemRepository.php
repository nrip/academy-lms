<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use DateTimeImmutable;

interface ContentItemRepository
{
    public function findById(int $contentId): ?ContentItem;

    public function findContextById(int $contentId): ?ContentItemContext;

    /** @return list<ContentItem> */
    public function listByModuleId(int $moduleId): array;

    /** @return list<ContentItem> */
    public function listByCourseVersionId(int $courseVersionId): array;

    public function nextSequence(int $moduleId): int;

    /**
     * @param array{
     *   module_id: int,
     *   sequence: int,
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
     * } $data
     */
    public function insert(array $data): int;

    /**
     * @param array{
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
     * } $data
     */
    public function update(int $contentId, array $data): bool;

    public function delete(int $contentId): bool;
}
