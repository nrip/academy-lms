<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentAttempt;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\Module;
use Academy\Domain\Learning\ContentProgress;
use Academy\Domain\Learning\Enrolment;

final class LearnerPlayerItemDetailView
{
    public function __construct(
        public readonly Enrolment $enrolment,
        public readonly string $courseTitle,
        public readonly string $versionTitle,
        public readonly Module $module,
        public readonly ContentItem $item,
        public readonly ContentProgress $progress,
        public readonly bool $canMarkComplete,
        public readonly ?string $markCompleteBlockedReason,
        public readonly ?int $previousContentId,
        public readonly ?int $nextContentId,
        public readonly ?Assessment $assessment = null,
        public readonly ?AssessmentAttempt $inProgressAttempt = null,
        public readonly int $assessmentAttemptsUsed = 0,
    ) {
    }
}
