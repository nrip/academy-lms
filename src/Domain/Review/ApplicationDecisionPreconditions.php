<?php

declare(strict_types=1);

namespace Academy\Domain\Review;

use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Courses\CourseDocumentRequirement;
use Academy\Domain\Credentials\DocumentScanStatus;
use Academy\Domain\Credentials\DocumentSubmission;
use Academy\Domain\Credentials\DocumentSubmissionStatus;
use Academy\Domain\Exception\DomainRuleException;

/**
 * Preconditions for reviewer application approval (→ payment_pending).
 */
final class ApplicationDecisionPreconditions
{
    /**
     * @param list<DocumentSubmission> $currentDocs
     * @param list<CourseDocumentRequirement> $requirements
     * @return list<string> Blocking reason codes (empty = ok)
     */
    public function evaluate(
        Application $application,
        array $currentDocs,
        array $requirements,
        ?ApplicationReviewAssignment $assignment,
        int $actingReviewerUserId,
    ): array {
        $blockers = [];

        if ($application->status !== ApplicationStatus::UNDER_REVIEW) {
            $blockers[] = 'application_not_under_review';
        }

        if ($assignment === null || !$assignment->isActive()) {
            $blockers[] = 'no_active_claim';
        } elseif ($assignment->reviewerUserId !== $actingReviewerUserId) {
            $blockers[] = 'claim_not_owned_by_actor';
        }

        $byRequirement = [];
        foreach ($currentDocs as $doc) {
            if ($doc->isCurrent()) {
                $byRequirement[$doc->requirementId] = $doc;
            }
        }

        foreach ($requirements as $requirement) {
            if (!$requirement->mandatory) {
                continue;
            }

            $current = $byRequirement[$requirement->requirementId] ?? null;
            if ($current === null) {
                $blockers[] = 'document_missing:' . $requirement->requirementId;
                continue;
            }

            if ($current->scanStatus === DocumentScanStatus::PENDING) {
                $blockers[] = 'document_scan_pending:' . $requirement->requirementId;
            } elseif ($current->scanStatus === DocumentScanStatus::FAILED
                || $current->status === DocumentSubmissionStatus::FAILED_SECURITY_SCAN
            ) {
                $blockers[] = 'document_scan_failed:' . $requirement->requirementId;
            } elseif ($current->scanStatus !== DocumentScanStatus::CLEAN) {
                $blockers[] = 'document_scan_not_clean:' . $requirement->requirementId;
            } elseif ($current->status !== DocumentSubmissionStatus::APPROVED) {
                $blockers[] = 'document_not_approved:' . $requirement->requirementId;
            }
        }

        foreach ($currentDocs as $doc) {
            if (!$doc->isCurrent()) {
                continue;
            }

            if (in_array($doc->status, [
                DocumentSubmissionStatus::REJECTED,
                DocumentSubmissionStatus::RESUBMISSION_REQUESTED,
            ], true)) {
                $blockers[] = 'document_blocking_status:' . $doc->requirementId;
            }

            if ($doc->scanStatus === DocumentScanStatus::PENDING) {
                $blockers[] = 'document_scan_pending:' . $doc->requirementId;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param list<string> $blockers
     */
    public function assertApproveAllowed(array $blockers): void
    {
        if ($blockers === []) {
            return;
        }

        throw new DomainRuleException(
            'Application cannot be approved: ' . implode(', ', $blockers),
        );
    }
}
