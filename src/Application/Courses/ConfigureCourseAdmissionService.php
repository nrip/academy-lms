<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Courses\EligibilityRuleRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDOException;
use Throwable;

final class ConfigureCourseAdmissionService
{
    private const ACCEPTED_FILE_TYPES = 'pdf,jpg,jpeg,png';
    private const MAX_SIZE_BYTES = 10485760;
    private const NAME_MAX = 128;
    private const DESCRIPTION_MAX = 4000;
    private const ORDER_MAX = 999;

    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly AuthorizationService $authorization,
        private readonly EligibilityRuleRepository $eligibilityRules,
        private readonly CourseDocumentRequirementRepository $documents,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    public function view(AuthContext $auth, int $courseId, int $versionId): AdmissionConfigurationView
    {
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $version = $this->access->requireVersionViewable($auth, $courseId, $versionId, $at);
        $draft = AdmissionEligibilityDraft::fromRules($this->eligibilityRules->listByCourseVersionId($versionId));
        $documents = $this->documents->listByCourseVersionId($versionId);
        $next = 1;
        foreach ($documents as $document) {
            $next = max($next, $document->sortOrder + 1);
        }

        return new AdmissionConfigurationView(
            course: $course,
            version: $version,
            categoryOptions: LearnerCategoryCatalog::labels(),
            selectedCategories: $draft->selectedLabels(),
            notes: $draft->notesText(),
            hasUnlistedCategories: $draft->hasUnlistedCategories(),
            documents: $documents,
            editable: !$version->isLocked() && $this->authorization->check($auth, 'course.version.edit'),
            nextDisplayOrder: $next,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveEligibility(AuthContext $auth, int $courseId, int $versionId, array $input): void
    {
        $actorUserId = $this->requireEditable($auth, $courseId, $versionId);
        $existing = AdmissionEligibilityDraft::fromRules($this->eligibilityRules->listByCourseVersionId($versionId));
        $draft = AdmissionEligibilityDraft::fromPosted($input['categories'] ?? null, (string) ($input['eligibility_notes'] ?? ''), $existing);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $this->eligibilityRules->deleteByCourseVersionId($versionId);
            foreach ($draft->persistenceRows() as $row) {
                $this->eligibilityRules->insert($row + ['course_version_id' => $versionId]);
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_version.eligibility_updated',
                    entityType: 'course_version',
                    entityId: (string) $versionId,
                    previous: [
                        'version_id' => $versionId,
                        'learner_categories' => implode(',', $existing->categoryKeys),
                        'note_count' => count($existing->notes),
                    ],
                    next: [
                        'version_id' => $versionId,
                        'learner_categories' => implode(',', $draft->categoryKeys),
                        'note_count' => count($draft->notes),
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack($pdo);
            throw $this->lockConflict($exception);
        } catch (Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addDocument(AuthContext $auth, int $courseId, int $versionId, array $input): int
    {
        $actorUserId = $this->requireEditable($auth, $courseId, $versionId);
        $fields = $this->documentFields($input, $this->nextDisplayOrder($versionId));

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $requirementId = $this->documents->insert([
                'course_version_id' => $versionId,
                'document_name' => $fields['name'],
                'description' => $fields['description'],
                'mandatory_flag' => $fields['mandatory'],
                'accepted_file_types' => self::ACCEPTED_FILE_TYPES,
                'max_size_bytes' => self::MAX_SIZE_BYTES,
                'single_or_multiple' => 'single',
                'reuse_allowed' => false,
                'reviewer_instructions' => null,
                'sort_order' => $fields['display_order'],
            ]);

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_document_requirement.created',
                    entityType: 'course_document_requirement',
                    entityId: (string) $requirementId,
                    next: [
                        'requirement_id' => $requirementId,
                        'version_id' => $versionId,
                        'name' => $fields['name'],
                        'description' => $fields['description'],
                        'mandatory_flag' => $fields['mandatory'] ? 1 : 0,
                        'sort_order' => $fields['display_order'],
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack($pdo);
            throw $this->lockConflict($exception);
        } catch (Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }

        return $requirementId;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateDocument(AuthContext $auth, int $courseId, int $versionId, int $requirementId, array $input): void
    {
        $actorUserId = $this->requireEditable($auth, $courseId, $versionId);
        $before = $this->requireDocument($versionId, $requirementId);
        $fields = $this->documentFields($input, $before->sortOrder);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $this->documents->updatePresentation(
                $requirementId,
                $fields['name'],
                $fields['description'],
                $fields['mandatory'],
                $fields['display_order'],
            );
            if ($this->documents->findById($requirementId) === null) {
                throw new ConflictException($this->lockedMessage());
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_document_requirement.updated',
                    entityType: 'course_document_requirement',
                    entityId: (string) $requirementId,
                    previous: [
                        'requirement_id' => $requirementId,
                        'version_id' => $versionId,
                        'name' => $before->documentName,
                        'mandatory_flag' => $before->mandatory ? 1 : 0,
                        'sort_order' => $before->sortOrder,
                    ],
                    next: [
                        'requirement_id' => $requirementId,
                        'version_id' => $versionId,
                        'name' => $fields['name'],
                        'description' => $fields['description'],
                        'mandatory_flag' => $fields['mandatory'] ? 1 : 0,
                        'sort_order' => $fields['display_order'],
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack($pdo);
            throw $this->lockConflict($exception);
        } catch (Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    public function removeDocument(AuthContext $auth, int $courseId, int $versionId, int $requirementId): void
    {
        $actorUserId = $this->requireEditable($auth, $courseId, $versionId);
        $before = $this->requireDocument($versionId, $requirementId);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            if (!$this->documents->delete($requirementId)) {
                throw new ConflictException($this->lockedMessage());
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_document_requirement.removed',
                    entityType: 'course_document_requirement',
                    entityId: (string) $requirementId,
                    previous: [
                        'requirement_id' => $requirementId,
                        'version_id' => $versionId,
                        'name' => $before->documentName,
                        'sort_order' => $before->sortOrder,
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack($pdo);
            throw $this->documentRemoveConflict($exception);
        } catch (Throwable $exception) {
            $this->rollBack($pdo);
            throw $exception;
        }
    }

    private function requireEditable(AuthContext $auth, int $courseId, int $versionId): int
    {
        $actorUserId = $this->access->requireUserId($auth);
        try {
            $this->access->requireVersionEditable($auth, $courseId, $versionId, $this->access->nowUtc());
        } catch (ConflictException) {
            throw new ConflictException($this->lockedMessage());
        }

        return $actorUserId;
    }

    private function requireDocument(int $versionId, int $requirementId): CourseDocumentRequirement
    {
        $document = $this->documents->findById($requirementId);
        if ($document === null || $document->courseVersionId !== $versionId) {
            throw new NotFoundException('Required document not found.');
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, description: string, mandatory: bool, display_order: int}
     */
    private function documentFields(array $input, int $defaultOrder): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Enter a document name.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new ValidationException('Document name must be 128 characters or fewer.');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new ValidationException('Description is too long.');
        }

        $orderRaw = trim((string) ($input['display_order'] ?? ''));
        if ($orderRaw === '') {
            $order = $defaultOrder;
        } elseif (preg_match('/^\d+$/', $orderRaw) !== 1) {
            throw new ValidationException('Display order must be a whole number from 0 to 999.');
        } else {
            $order = (int) $orderRaw;
        }
        if ($order < 0 || $order > self::ORDER_MAX) {
            throw new ValidationException('Display order must be a whole number from 0 to 999.');
        }

        return [
            'name' => $name,
            'description' => $description,
            'mandatory' => isset($input['mandatory']) && (string) $input['mandatory'] !== '0',
            'display_order' => $order,
        ];
    }

    private function nextDisplayOrder(int $versionId): int
    {
        $next = 1;
        foreach ($this->documents->listByCourseVersionId($versionId) as $document) {
            $next = max($next, $document->sortOrder + 1);
        }

        return $next;
    }

    private function lockConflict(PDOException $exception): Throwable
    {
        $info = $exception->errorInfo;
        $sqlState = (string) ($info[0] ?? '');
        $driverCode = (int) ($info[1] ?? 0);
        if ($sqlState === '45000' || $driverCode === 1644) {
            return new ConflictException($this->lockedMessage());
        }

        return $exception;
    }

    private function documentRemoveConflict(PDOException $exception): Throwable
    {
        $locked = $this->lockConflict($exception);
        if ($locked instanceof ConflictException) {
            return $locked;
        }

        $info = $exception->errorInfo;
        if ((string) ($info[0] ?? '') === '23000') {
            return new ConflictException('This document cannot be removed because an application already uses it.');
        }

        return $exception;
    }

    private function lockedMessage(): string
    {
        return 'This edition cannot be changed. Create the next edition to update eligibility or required documents.';
    }

    private function rollBack(\PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
