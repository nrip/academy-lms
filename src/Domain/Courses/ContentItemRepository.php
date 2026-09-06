<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

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
     *   mandatory_flag: bool,
     *   completion_rule: string
     * } $data
     */
    public function insert(array $data): int;

    /**
     * @param array{
     *   title: string,
     *   body_text: ?string,
     *   object_key: ?string,
     *   mandatory_flag: bool,
     *   completion_rule: string
     * } $data
     */
    public function update(int $contentId, array $data): bool;

    public function delete(int $contentId): bool;
}
