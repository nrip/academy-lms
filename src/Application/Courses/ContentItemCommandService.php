<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\ContentCompletionRule;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
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

        $fields = $this->normalizeCreate($input);

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
                'mandatory_flag' => $fields['mandatory_flag'],
                'completion_rule' => $fields['completion_rule'],
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
        $fields = $this->normalizeUpdate($input, $before->contentType);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $updated = $this->contentItems->update($contentId, $fields);
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
                    ],
                    next: [
                        'content_id' => $contentId,
                        'title' => $fields['title'],
                        'content_type' => $before->contentType,
                        'body_text_length' => $fields['body_text'] === null ? 0 : mb_strlen($fields['body_text']),
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

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   content_type: string,
     *   title: string,
     *   body_text: ?string,
     *   object_key: ?string,
     *   mandatory_flag: bool,
     *   completion_rule: string
     * }
     */
    private function normalizeCreate(array $input): array
    {
        $type = ContentItemType::assertCreatable(
            trim((string) ($input['content_type'] ?? ContentItemType::TEXT_LESSON)),
        );
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('Content title is required.');
        }
        if (mb_strlen($title) > 255) {
            throw new ValidationException('Content title must be 255 characters or fewer.');
        }

        $body = trim((string) ($input['body_text'] ?? ''));
        $objectKey = trim((string) ($input['object_key'] ?? ''));
        $bodyText = $body === '' ? null : $body;
        $objectKeyValue = $objectKey === '' ? null : $objectKey;

        if ($type === ContentItemType::TEXT_LESSON && $bodyText === null) {
            throw new ValidationException('Text lesson body is required.');
        }
        if ($type === ContentItemType::PDF && $objectKeyValue === null) {
            throw new ValidationException('PDF content requires an object key (storage reference).');
        }
        if ($type === ContentItemType::TEXT_LESSON) {
            $objectKeyValue = null;
        }
        if ($type === ContentItemType::PDF) {
            $bodyText = null;
        }
        if ($type === ContentItemType::MCQ_ASSESSMENT) {
            $bodyText = null;
            $objectKeyValue = null;
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
            'object_key' => $objectKeyValue,
            'mandatory_flag' => $mandatory,
            'completion_rule' => $completion,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   title: string,
     *   body_text: ?string,
     *   object_key: ?string,
     *   mandatory_flag: bool,
     *   completion_rule: string
     * }
     */
    private function normalizeUpdate(array $input, string $existingType): array
    {
        $merged = $this->normalizeCreate($input + ['content_type' => $existingType]);

        return [
            'title' => $merged['title'],
            'body_text' => $merged['body_text'],
            'object_key' => $merged['object_key'],
            'mandatory_flag' => $merged['mandatory_flag'],
            'completion_rule' => $merged['completion_rule'],
        ];
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
