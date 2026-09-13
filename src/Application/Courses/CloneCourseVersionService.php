<?php

declare(strict_types=1);

namespace Academy\Application\Courses;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Assessments\AssessmentQuestionLinkRepository;
use Academy\Domain\Assessments\AssessmentRepository;
use Academy\Domain\Audit\CoursesAuditPayload;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Courses\CourseVersion;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\EligibilityRuleRepository;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDOException;
use Throwable;

final class CloneCourseVersionService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly CourseVersionRepository $courseVersions,
        private readonly EligibilityRuleRepository $eligibilityRules,
        private readonly CourseDocumentRequirementRepository $documentRequirements,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentQuestionLinkRepository $assessmentLinks,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    public function clone(AuthContext $auth, int $courseId, int $sourceVersionId): CourseVersion
    {
        $at = $this->access->nowUtc();
        $actorUserId = $this->access->requireUserId($auth);
        $source = $this->access->requireVersionWithPermission(
            $auth,
            $courseId,
            $sourceVersionId,
            'course.version.clone',
            $at,
        );

        $nextNumber = $this->courseVersions->nextVersionNumber($courseId);
        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        $newVersionId = 0;
        try {
            $newVersionId = $this->courseVersions->insertDraft([
                'course_id' => $courseId,
                'version_number' => $nextNumber,
                'title' => $source->title,
                'description' => $source->description,
                'learning_objectives' => $source->learningObjectives,
                'intended_audience' => $source->intendedAudience,
                'syllabus_summary' => $source->syllabusSummary,
                'admission_mode' => $source->admissionMode,
                'delivery_type' => $source->deliveryType,
                'duration_text' => $source->durationText,
                'validity_period_days' => $source->validityPeriodDays,
                'standard_fee' => $source->standardFee,
                'gst_rate' => $source->gstRate,
                'currency' => $source->currency,
                'certificate_type' => $source->certificateType,
                'faq' => $source->faq,
                'cloned_from_version_id' => $source->versionId,
            ]);

            foreach ($this->eligibilityRules->listByCourseVersionId($sourceVersionId) as $rule) {
                $this->eligibilityRules->insert([
                    'course_version_id' => $newVersionId,
                    'field' => $rule->field,
                    'operator' => $rule->operator,
                    'value' => $rule->value,
                    'logic_group' => $rule->logicGroup,
                    'display_label' => $rule->displayLabel,
                    'sort_order' => $rule->sortOrder,
                ]);
            }

            foreach ($this->documentRequirements->listByCourseVersionId($sourceVersionId) as $requirement) {
                $this->documentRequirements->insert([
                    'course_version_id' => $newVersionId,
                    'document_name' => $requirement->documentName,
                    'description' => $requirement->description,
                    'mandatory_flag' => $requirement->mandatory,
                    'accepted_file_types' => $requirement->acceptedFileTypes,
                    'max_size_bytes' => $requirement->maxSizeBytes,
                    'single_or_multiple' => $requirement->singleOrMultiple,
                    'reuse_allowed' => $requirement->reuseAllowed,
                    'reviewer_instructions' => $requirement->reviewerInstructions,
                    'sort_order' => $requirement->sortOrder,
                ]);
            }

            /** @var array<int, int> $moduleMap old module_id => new module_id */
            $moduleMap = [];
            $sourceModules = $this->modules->listByCourseVersionId($sourceVersionId);
            foreach ($sourceModules as $module) {
                $prereq = null;
                if ($module->prerequisiteModuleId !== null && isset($moduleMap[$module->prerequisiteModuleId])) {
                    $prereq = $moduleMap[$module->prerequisiteModuleId];
                }
                $newModuleId = $this->modules->insert([
                    'course_version_id' => $newVersionId,
                    'sequence' => $module->sequence,
                    'title' => $module->title,
                    'description' => $module->description,
                    'mandatory_flag' => $module->mandatoryFlag,
                    'release_rule' => $module->releaseRule,
                    'prerequisite_module_id' => $prereq,
                ]);
                $moduleMap[$module->moduleId] = $newModuleId;
            }

            // Fix prerequisites that were out of sequence order.
            foreach ($sourceModules as $module) {
                if ($module->prerequisiteModuleId === null) {
                    continue;
                }
                $newModuleId = $moduleMap[$module->moduleId];
                $newPrereq = $moduleMap[$module->prerequisiteModuleId] ?? null;
                $this->modules->update($newModuleId, [
                    'title' => $module->title,
                    'description' => $module->description,
                    'mandatory_flag' => $module->mandatoryFlag,
                    'release_rule' => $module->releaseRule,
                    'prerequisite_module_id' => $newPrereq,
                ]);
            }

            foreach ($sourceModules as $module) {
                $newModuleId = $moduleMap[$module->moduleId];
                foreach ($this->contentItems->listByModuleId($module->moduleId) as $item) {
                    $newContentId = $this->contentItems->insert([
                        'module_id' => $newModuleId,
                        'sequence' => $item->sequence,
                        'content_type' => $item->contentType,
                        'title' => $item->title,
                        'body_text' => $item->bodyText,
                        'object_key' => $item->objectKey,
                        'video_url' => $item->videoUrl,
                        'video_delivery_mode' => $item->videoDeliveryMode,
                        'video_provider' => $item->videoProvider,
                        'mandatory_flag' => $item->mandatoryFlag,
                        'completion_rule' => $item->completionRule,
                        'original_filename' => $item->delivery->originalFilename,
                        'media_mime' => $item->delivery->mediaMime,
                        'media_bytes' => $item->delivery->mediaBytes,
                        'media_sha256' => $item->delivery->mediaSha256,
                        'podcast_url' => $item->delivery->podcastUrl,
                        'live_join_url' => $item->delivery->liveJoinUrl,
                        'live_starts_at' => $item->delivery->liveStartsAt,
                        'live_ends_at' => $item->delivery->liveEndsAt,
                        'live_provider' => $item->delivery->liveProvider,
                        'live_recording_url' => $item->delivery->liveRecordingUrl,
                        'live_external_meeting_id' => $item->delivery->liveExternalMeetingId,
                    ]);

                    if ($item->contentType !== ContentItemType::MCQ_ASSESSMENT) {
                        continue;
                    }

                    $assessment = $this->assessments->findByContentId($item->contentId);
                    if ($assessment === null) {
                        continue;
                    }

                    $newAssessmentId = $this->assessments->insert([
                        'content_id' => $newContentId,
                        'title' => $assessment->title,
                        'questions_per_attempt' => $assessment->questionsPerAttempt,
                        'pass_threshold_percent' => $assessment->passThresholdPercent,
                        'time_limit_seconds' => $assessment->timeLimitSeconds,
                        'max_attempts' => $assessment->maxAttempts,
                        'cooldown_seconds' => $assessment->cooldownSeconds,
                        'randomise_questions' => $assessment->randomiseQuestions,
                        'randomise_options' => $assessment->randomiseOptions,
                    ]);

                    foreach ($this->assessmentLinks->listByAssessmentId($assessment->assessmentId) as $link) {
                        $this->assessmentLinks->insert([
                            'assessment_id' => $newAssessmentId,
                            'question_id' => $link->questionId,
                            'sequence' => $link->sequence,
                        ]);
                    }
                }
            }

            $this->audit->record(
                new CoursesAuditPayload(
                    action: 'course_version.cloned',
                    entityType: 'course_version',
                    entityId: (string) $newVersionId,
                    previous: [
                        'version_id' => $sourceVersionId,
                        'version_number' => $source->versionNumber,
                    ],
                    next: [
                        'version_id' => $newVersionId,
                        'course_id' => $courseId,
                        'version_number' => $nextNumber,
                        'cloned_from_version_id' => $sourceVersionId,
                        'status' => 'draft',
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

        $cloned = $this->courseVersions->findById($newVersionId);
        if ($cloned === null) {
            throw new ConflictException('Cloned CourseVersion could not be reloaded.');
        }

        return $cloned;
    }
}
