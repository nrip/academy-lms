<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\Module;
use Academy\Domain\Courses\ModuleReleaseRule;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;

final class ModuleCommandService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly ModuleRepository $modules,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(AuthContext $auth, int $courseId, int $versionId, array $input): Module
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $this->access->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'module.manage', $at);

        $fields = $this->normalize($input, $versionId, null);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $moduleId = $this->modules->insert([
                'course_version_id' => $versionId,
                'sequence' => $this->modules->nextSequence($versionId),
                'title' => $fields['title'],
                'description' => $fields['description'],
                'mandatory_flag' => $fields['mandatory_flag'],
                'release_rule' => $fields['release_rule'],
                'prerequisite_module_id' => $fields['prerequisite_module_id'],
            ]);

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'module.created',
                    entityType: 'module',
                    entityId: (string) $moduleId,
                    next: [
                        'module_id' => $moduleId,
                        'version_id' => $versionId,
                        'title' => $fields['title'],
                        'release_rule' => $fields['release_rule'],
                        'mandatory_flag' => $fields['mandatory_flag'] ? 1 : 0,
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

        return $this->requireModule($moduleId);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(AuthContext $auth, int $courseId, int $versionId, int $moduleId, array $input): Module
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $this->access->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'module.manage', $at);

        $before = $this->requireModuleForVersion($moduleId, $versionId);
        $fields = $this->normalize($input, $versionId, $moduleId);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $updated = $this->modules->update($moduleId, $fields);
            if (!$updated) {
                throw new ConflictException(
                    'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
                );
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'module.updated',
                    entityType: 'module',
                    entityId: (string) $moduleId,
                    previous: [
                        'module_id' => $before->moduleId,
                        'title' => $before->title,
                        'release_rule' => $before->releaseRule,
                        'mandatory_flag' => $before->mandatoryFlag ? 1 : 0,
                    ],
                    next: [
                        'module_id' => $moduleId,
                        'title' => $fields['title'],
                        'release_rule' => $fields['release_rule'],
                        'mandatory_flag' => $fields['mandatory_flag'] ? 1 : 0,
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

        return $this->requireModule($moduleId);
    }

    public function delete(AuthContext $auth, int $courseId, int $versionId, int $moduleId): void
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $this->access->requireVersionMutableWithPermission($auth, $courseId, $versionId, 'module.manage', $at);

        $before = $this->requireModuleForVersion($moduleId, $versionId);
        if ($this->modules->countContentItems($moduleId) > 0) {
            throw new ConflictException('Remove content items from this module before deleting it.');
        }

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            if (!$this->modules->delete($moduleId)) {
                throw new ConflictException(
                    'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
                );
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'module.deleted',
                    entityType: 'module',
                    entityId: (string) $moduleId,
                    previous: [
                        'module_id' => $before->moduleId,
                        'version_id' => $before->courseVersionId,
                        'title' => $before->title,
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
     *   title: string,
     *   description: string,
     *   mandatory_flag: bool,
     *   release_rule: string,
     *   prerequisite_module_id: ?int
     * }
     */
    private function normalize(array $input, int $versionId, ?int $currentModuleId): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($title === '') {
            throw new ValidationException('Module title is required.');
        }
        if (mb_strlen($title) > 255) {
            throw new ValidationException('Module title must be 255 characters or fewer.');
        }

        $releaseRule = ModuleReleaseRule::assertValid(
            trim((string) ($input['release_rule'] ?? ModuleReleaseRule::IMMEDIATE)),
        );
        $mandatory = isset($input['mandatory_flag']) && (
            (string) $input['mandatory_flag'] === '1'
            || $input['mandatory_flag'] === true
            || $input['mandatory_flag'] === 1
        );

        $prerequisiteId = null;
        $prereqRaw = $input['prerequisite_module_id'] ?? null;
        if ($prereqRaw !== null && $prereqRaw !== '') {
            $prerequisiteId = (int) $prereqRaw;
            if ($prerequisiteId < 1) {
                throw new ValidationException('Prerequisite module is invalid.');
            }
            if ($currentModuleId !== null && $prerequisiteId === $currentModuleId) {
                throw new ValidationException('A module cannot be its own prerequisite.');
            }
            $prereq = $this->modules->findById($prerequisiteId);
            if ($prereq === null || $prereq->courseVersionId !== $versionId) {
                throw new ValidationException('Prerequisite module must belong to this course version.');
            }
        } elseif ($releaseRule === ModuleReleaseRule::SEQUENTIAL) {
            $siblings = $this->modules->listByCourseVersionId($versionId);
            foreach (array_reverse($siblings) as $sibling) {
                if ($currentModuleId !== null && $sibling->moduleId === $currentModuleId) {
                    continue;
                }
                $prerequisiteId = $sibling->moduleId;
                break;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'mandatory_flag' => $mandatory,
            'release_rule' => $releaseRule,
            'prerequisite_module_id' => $prerequisiteId,
        ];
    }

    private function requireModule(int $moduleId): Module
    {
        $module = $this->modules->findById($moduleId);
        if ($module === null) {
            throw new ConflictException('Module could not be loaded after save.');
        }

        return $module;
    }

    private function requireModuleForVersion(int $moduleId, int $versionId): Module
    {
        $module = $this->modules->findById($moduleId);
        if ($module === null || $module->courseVersionId !== $versionId) {
            throw new NotFoundException('Module not found.');
        }

        return $module;
    }
}
