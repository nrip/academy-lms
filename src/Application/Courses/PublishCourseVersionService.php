<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Assessments\AssessmentQuestionLinkRepository;
use Academy\Domain\Assessments\AssessmentRepository;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionPublishValidator;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\CourseVersionStateMachine;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Courses\CourseVersionStatusHistoryRepository;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDOException;
use Throwable;

final class PublishCourseVersionService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseVersionRepository $courseVersions,
        private readonly CourseRepository $courses,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentQuestionLinkRepository $assessmentLinks,
        private readonly CourseVersionPublishValidator $publishValidator,
        private readonly CourseVersionStateMachine $stateMachine,
        private readonly CourseVersionStatusHistoryRepository $statusHistory,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    public function publish(AuthContext $auth, int $courseId, int $versionId): CourseVersion
    {
        $at = $this->access->nowUtc();
        $actorUserId = $this->access->requireUserId($auth);
        $version = $this->access->requireVersionMutableWithPermission(
            $auth,
            $courseId,
            $versionId,
            'course.version.publish',
            $at,
        );

        $modules = $this->modules->listByCourseVersionId($versionId);
        $contentItems = $this->contentItems->listByCourseVersionId($versionId);
        $assessmentByContentId = [];
        $linksByAssessmentId = [];
        foreach ($contentItems as $item) {
            if ($item->contentType !== ContentItemType::MCQ_ASSESSMENT) {
                continue;
            }
            $assessment = $this->assessments->findByContentId($item->contentId);
            $assessmentByContentId[$item->contentId] = $assessment;
            if ($assessment !== null) {
                $linksByAssessmentId[$assessment->assessmentId] = $this->assessmentLinks->listByAssessmentId(
                    $assessment->assessmentId,
                );
            }
        }

        $this->publishValidator->assertReady(
            $version,
            $modules,
            $contentItems,
            $assessmentByContentId,
            $linksByAssessmentId,
        );
        $this->stateMachine->assertCanTransition($version->status, CourseVersionStatus::PUBLISHED);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            if (!$this->courseVersions->publishAndLock($versionId, $at)) {
                throw new ConflictException('CourseVersion could not be published (it may already be locked).');
            }

            $this->courses->setCurrentPublishedVersionId($courseId, $versionId);
            $this->statusHistory->append(
                $versionId,
                CourseVersionStatus::DRAFT,
                CourseVersionStatus::PUBLISHED,
                $actorUserId,
                'published',
                $at,
            );

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_version.published',
                    entityType: 'course_version',
                    entityId: (string) $versionId,
                    previous: [
                        'version_id' => $versionId,
                        'status' => CourseVersionStatus::DRAFT,
                        'locked_reason' => null,
                    ],
                    next: [
                        'version_id' => $versionId,
                        'course_id' => $courseId,
                        'status' => CourseVersionStatus::PUBLISHED,
                        'locked_reason' => 'published',
                        'published_at' => $at->format('Y-m-d H:i:s'),
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $published = $this->courseVersions->findById($versionId);
        if ($published === null) {
            throw new ConflictException('Published CourseVersion could not be reloaded.');
        }

        return $published;
    }
}
