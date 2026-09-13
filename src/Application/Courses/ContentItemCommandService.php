<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemDraftNormalizer;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;

final class ContentItemCommandService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
        private readonly ContentItemDraftNormalizer $drafts = new ContentItemDraftNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(AuthContext $auth, int $courseId, int $versionId, int $moduleId, array $input): ContentItem
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $this->access->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'content.manage', $at);
        $this->requireModuleForVersion($moduleId, $versionId);

        $fields = $this->drafts->normalize($input);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $contentId = $this->contentItems->insert([
                'module_id' => $moduleId,
                'sequence' => $this->contentItems->nextSequence($moduleId),
                'content_type' => $fields['content_type'],
                'title' => $fields['title'],
                'body_text' => $fields['body_text'],
                'object_key' => $fields['object_key'],
                'video_url' => $fields['video_url'],
                'video_delivery_mode' => $fields['video_delivery_mode'],
                'video_provider' => $fields['video_provider'],
                'mandatory_flag' => $fields['mandatory_flag'],
                'completion_rule' => $fields['completion_rule'],
                'original_filename' => $fields['original_filename'],
                'media_mime' => $fields['media_mime'],
                'media_bytes' => $fields['media_bytes'],
                'media_sha256' => $fields['media_sha256'],
                'podcast_url' => $fields['podcast_url'],
                'live_join_url' => $fields['live_join_url'],
                'live_starts_at' => $fields['live_starts_at'],
                'live_ends_at' => $fields['live_ends_at'],
                'live_provider' => $fields['live_provider'],
                'live_recording_url' => $fields['live_recording_url'],
                'live_external_meeting_id' => $fields['live_external_meeting_id'],
            ]);

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'content_item.created',
                    entityType: 'content_item',
                    entityId: (string) $contentId,
                    next: [
                        'content_id' => $contentId,
                        'module_id' => $moduleId,
                        'version_id' => $versionId,
                        'title' => $fields['title'],
                        'content_type' => $fields['content_type'],
                        'body_text_length' => $fields['body_text'] === null ? 0 : mb_strlen($fields['body_text']),
                        'object_key_present' => $fields['object_key'] === null ? 0 : 1,
                        'video_provider' => $fields['video_provider'],
                        'video_delivery_mode' => $fields['video_delivery_mode'],
                        'media_mime' => $fields['media_mime'],
                        'live_provider' => $fields['live_provider'],
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->requireContent($contentId);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(
        AuthContext $auth,
        int $courseId,
        int $versionId,
        int $moduleId,
        int $contentId,
        array $input,
    ): ContentItem {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $this->access->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'content.manage', $at);
        $this->requireModuleForVersion($moduleId, $versionId);

        $before = $this->requireContentForModule($contentId, $moduleId);
        $fields = $this->drafts->normalize($input + ['content_type' => $before->contentType]);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $updated = $this->contentItems->update($contentId, [
                'title' => $fields['title'],
                'body_text' => $fields['body_text'],
                'object_key' => $fields['object_key'],
                'video_url' => $fields['video_url'],
                'video_delivery_mode' => $fields['video_delivery_mode'],
                'video_provider' => $fields['video_provider'],
                'mandatory_flag' => $fields['mandatory_flag'],
                'completion_rule' => $fields['completion_rule'],
                'original_filename' => $fields['original_filename'],
                'media_mime' => $fields['media_mime'],
                'media_bytes' => $fields['media_bytes'],
                'media_sha256' => $fields['media_sha256'],
                'podcast_url' => $fields['podcast_url'],
                'live_join_url' => $fields['live_join_url'],
                'live_starts_at' => $fields['live_starts_at'],
                'live_ends_at' => $fields['live_ends_at'],
                'live_provider' => $fields['live_provider'],
                'live_recording_url' => $fields['live_recording_url'],
                'live_external_meeting_id' => $fields['live_external_meeting_id'],
            ]);
            if (!$updated) {
                throw new ConflictException(
                    'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
                );
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'content_item.updated',
                    entityType: 'content_item',
                    entityId: (string) $contentId,
                    previous: [
                        'content_id' => $before->contentId,
                        'title' => $before->title,
                        'content_type' => $before->contentType,
                        'body_text_length' => $before->bodyText === null ? 0 : mb_strlen($before->bodyText),
                        'video_provider' => $before->videoProvider,
                    ],
                    next: [
                        'content_id' => $contentId,
                        'title' => $fields['title'],
                        'content_type' => $before->contentType,
                        'body_text_length' => $fields['body_text'] === null ? 0 : mb_strlen($fields['body_text']),
                        'video_provider' => $fields['video_provider'],
                        'video_delivery_mode' => $fields['video_delivery_mode'],
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->requireContent($contentId);
    }

    public function delete(
        AuthContext $auth,
        int $courseId,
        int $versionId,
        int $moduleId,
        int $contentId,
    ): void {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $this->access->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'content.manage', $at);
        $this->requireModuleForVersion($moduleId, $versionId);
        $before = $this->requireContentForModule($contentId, $moduleId);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            if (!$this->contentItems->delete($contentId)) {
                throw new ConflictException(
                    'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
                );
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'content_item.deleted',
                    entityType: 'content_item',
                    entityId: (string) $contentId,
                    previous: [
                        'content_id' => $before->contentId,
                        'module_id' => $before->moduleId,
                        'title' => $before->title,
                        'content_type' => $before->contentType,
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function requireModuleForVersion(int $moduleId, int $versionId): void
    {
        $module = $this->modules->findById($moduleId);
        if ($module === null || $module->courseVersionId !== $versionId) {
            throw new NotFoundException('Module not found.');
        }
    }

    private function requireContent(int $contentId): ContentItem
    {
        $item = $this->contentItems->findById($contentId);
        if ($item === null) {
            throw new ConflictException('Content item could not be loaded after save.');
        }

        return $item;
    }

    private function requireContentForModule(int $contentId, int $moduleId): ContentItem
    {
        $item = $this->contentItems->findById($contentId);
        if ($item === null || $item->moduleId !== $moduleId) {
            throw new NotFoundException('Content item not found.');
        }

        return $item;
    }
}
