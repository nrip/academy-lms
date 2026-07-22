<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;

/**
 * Whether a reviewer may act on a document submission row.
 */
final class DocumentReviewPolicy
{
    public function canReview(
        Application $application,
        DocumentSubmission $submission,
    ): bool {
        if (!$submission->isCurrent()) {
            return false;
        }

        if ($submission->status !== DocumentSubmissionStatus::UNDER_REVIEW) {
            return false;
        }

        if ($submission->scanStatus !== DocumentScanStatus::CLEAN) {
            return false;
        }

        return in_array($application->status, [
            ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::RESUBMISSION_REQUESTED,
        ], true);
    }

    public function assertCanReview(
        Application $application,
        DocumentSubmission $submission,
    ): void {
        if ($this->canReview($application, $submission)) {
            return;
        }

        throw new DomainRuleException('Document is not available for reviewer action.');
    }
}
