<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentPublishCompletenessValidator;
use Academy\Domain\Assessments\AssessmentQuestionLink;
use Academy\Domain\Exception\ValidationException;

/**
 * Demo publish readiness for Draft CourseVersions.
 */
final class CourseVersionPublishValidator
{
    public function __construct(
        private readonly AssessmentPublishCompletenessValidator $assessmentCompleteness = new AssessmentPublishCompletenessValidator(),
    ) {
    }

    /**
     * @param list<Module> $modules
     * @param list<ContentItem> $contentItems
     * @param array<int, ?Assessment> $assessmentByContentId
     * @param array<int, list<AssessmentQuestionLink>> $linksByAssessmentId
     * @return list<string>
     */
    public function blockers(
        CourseVersion $version,
        array $modules,
        array $contentItems,
        array $assessmentByContentId,
        array $linksByAssessmentId,
    ): array {
        $blockers = [];

        if ($version->status !== CourseVersionStatus::DRAFT) {
            $blockers[] = 'Only Draft CourseVersions can be published.';
        }
        if ($version->isLocked()) {
            $blockers[] = 'Locked CourseVersions cannot be published again.';
        }

        foreach (
            [
                'Title' => $version->title,
                'Description' => $version->description,
                'Learning objectives' => $version->learningObjectives,
                'Intended audience' => $version->intendedAudience,
                'Syllabus summary' => $version->syllabusSummary,
                'Delivery type' => $version->deliveryType,
                'Duration' => $version->durationText,
                'Certificate type' => $version->certificateType,
            ] as $label => $value
        ) {
            if (trim($value) === '' || str_contains($value, 'Draft — update before publish')) {
                $blockers[] = $label . ' must be completed before publish.';
            }
        }

        if ((float) $version->standardFee <= 0) {
            $blockers[] = 'Standard fee must be greater than zero before publish.';
        }

        if ($modules === []) {
            $blockers[] = 'Add at least one module before publish.';
        }

        if ($contentItems === []) {
            $blockers[] = 'Add at least one content item before publish.';
        }

        foreach ($contentItems as $item) {
            if ($item->contentType !== ContentItemType::MCQ_ASSESSMENT) {
                continue;
            }
            $assessment = $assessmentByContentId[$item->contentId] ?? null;
            $links = [];
            if ($assessment !== null) {
                $links = $linksByAssessmentId[$assessment->assessmentId] ?? [];
            }
            foreach ($this->assessmentCompleteness->blockers($assessment, $links) as $message) {
                $blockers[] = 'Content "' . $item->title . '": ' . $message;
            }
        }

        return $blockers;
    }

    /**
     * @param list<Module> $modules
     * @param list<ContentItem> $contentItems
     * @param array<int, ?Assessment> $assessmentByContentId
     * @param array<int, list<AssessmentQuestionLink>> $linksByAssessmentId
     */
    public function assertReady(
        CourseVersion $version,
        array $modules,
        array $contentItems,
        array $assessmentByContentId,
        array $linksByAssessmentId,
    ): void {
        $blockers = $this->blockers(
            $version,
            $modules,
            $contentItems,
            $assessmentByContentId,
            $linksByAssessmentId,
        );
        if ($blockers !== []) {
            throw new ValidationException(implode(' ', $blockers));
        }
    }
}
