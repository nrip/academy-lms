<?php

declare(strict_types=1);

namespace Academy\Application\Review;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Identity\LearnerQualification;
use Academy\Domain\Review\ApplicationReviewAssignment;
use DateTimeImmutable;

/**
 * R-02 application detail for scoped reviewers (no learner email/mobile).
 */
final class ReviewerApplicationDetailView
{
    /**
     * @param list<LearnerQualification> $qualifications
     * @param list<ReviewerDocumentChecklistItem> $documentChecklist
     * @param list<ReviewerVerificationHistoryItem> $verificationHistory
     */
    public function __construct(
        public readonly Application $application,
        public readonly string $courseTitle,
        public readonly string $batchLabel,
        public readonly ?LearnerProfile $profileSummary,
        public readonly array $qualifications,
        public readonly array $documentChecklist,
        public readonly array $verificationHistory,
        public readonly ?ApplicationReviewAssignment $activeAssignment,
        public readonly ?DateTimeImmutable $submittedAt,
    ) {
    }
}
