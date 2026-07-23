<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Payments\PaymentStatus;

/**
 * Central learner-facing status mapping for dashboard and notifications (WP-07).
 * Does not expose internal implementation terms.
 */
final class LearnerStatusPresenter
{
    public function applicationPresentation(string $status): LearnerStatusView
    {
        return match ($status) {
            ApplicationStatus::DRAFT => new LearnerStatusView(
                'draft',
                'Draft',
                'Complete your application to continue.',
                'complete_application',
                'Complete your application',
                'info',
            ),
            ApplicationStatus::SUBMITTED => new LearnerStatusView(
                'submitted',
                'Submitted',
                'Your application has been submitted and will move to review shortly.',
                'wait',
                null,
                'info',
            ),
            ApplicationStatus::DOCUMENTS_INCOMPLETE => new LearnerStatusView(
                'documents_incomplete',
                'Documents incomplete',
                'Upload the required documents to continue.',
                'upload_documents',
                'Upload documents',
                'warning',
            ),
            ApplicationStatus::UNDER_REVIEW => new LearnerStatusView(
                'under_review',
                'Under review',
                'Your documents are being reviewed. No action is needed right now.',
                'wait',
                null,
                'info',
            ),
            ApplicationStatus::RESUBMISSION_REQUESTED => new LearnerStatusView(
                'resubmission_requested',
                'Corrections required',
                'Please correct the requested documents and resubmit.',
                'correct_documents',
                'Correct documents',
                'warning',
            ),
            ApplicationStatus::PAYMENT_PENDING => new LearnerStatusView(
                'payment_pending',
                'Payment required',
                'Your application is approved. Complete payment to continue admission.',
                'pay',
                'Pay now',
                'warning',
            ),
            ApplicationStatus::AWAITING_VERIFICATION => new LearnerStatusView(
                'awaiting_verification',
                'Awaiting verification',
                'Additional verification is in progress. Please wait.',
                'wait',
                null,
                'info',
            ),
            ApplicationStatus::ADMITTED => new LearnerStatusView(
                'admitted',
                'Admitted',
                'You have been admitted. See your enrolment below.',
                'none',
                null,
                'success',
            ),
            ApplicationStatus::REJECTED => new LearnerStatusView(
                'rejected',
                'Not approved',
                'This application was not approved.',
                'none',
                null,
                'danger',
            ),
            ApplicationStatus::WITHDRAWN => new LearnerStatusView(
                'withdrawn',
                'Withdrawn',
                'This application has been withdrawn.',
                'none',
                null,
                'muted',
            ),
            ApplicationStatus::EXPIRED => new LearnerStatusView(
                'expired',
                'Expired',
                'This application has expired.',
                'none',
                null,
                'muted',
            ),
            default => new LearnerStatusView(
                $status,
                'Application update',
                'See your application for details.',
                'none',
                null,
                'muted',
            ),
        };
    }

    public function paymentPresentation(string $status): LearnerStatusView
    {
        return match ($status) {
            PaymentStatus::CREATED,
            PaymentStatus::PENDING => new LearnerStatusView(
                $status,
                'Confirming payment',
                'Browser return is not final confirmation. Please wait while payment is verified.',
                'wait_confirmation',
                null,
                'info',
            ),
            PaymentStatus::SUCCESSFUL => new LearnerStatusView(
                'successful',
                'Payment successful',
                'Payment has been recorded successfully.',
                'none',
                null,
                'success',
            ),
            PaymentStatus::RECONCILIATION_PENDING => new LearnerStatusView(
                'reconciliation_pending',
                'Payment under verification',
                'Your payment is being verified. Enrolment is not confirmed yet.',
                'wait_reconciliation',
                null,
                'warning',
            ),
            PaymentStatus::FAILED => new LearnerStatusView(
                'failed',
                'Payment unsuccessful',
                'This payment attempt did not complete. You may retry when eligible.',
                'retry_payment',
                'Retry payment',
                'danger',
            ),
            PaymentStatus::CANCELLED => new LearnerStatusView(
                'cancelled',
                'Payment cancelled',
                'This payment attempt was cancelled. You may retry when eligible.',
                'retry_payment',
                'Retry payment',
                'warning',
            ),
            PaymentStatus::EXPIRED => new LearnerStatusView(
                'expired',
                'Payment expired',
                'This payment attempt expired. You may retry when eligible.',
                'retry_payment',
                'Retry payment',
                'warning',
            ),
            default => new LearnerStatusView(
                $status,
                'Payment update',
                'See payment status for details.',
                'none',
                null,
                'muted',
            ),
        };
    }

    public function enrolmentPresentation(string $lifecycleStatus): LearnerStatusView
    {
        return match ($lifecycleStatus) {
            EnrolmentLifecycleStatus::SCHEDULED => new LearnerStatusView(
                'scheduled',
                'Scheduled',
                'You are enrolled. The batch has not started yet.',
                'none',
                null,
                'info',
            ),
            EnrolmentLifecycleStatus::ACTIVE => new LearnerStatusView(
                'active',
                'Active',
                'You are enrolled and the batch is active.',
                'none',
                null,
                'success',
            ),
            EnrolmentLifecycleStatus::SUSPENDED => new LearnerStatusView(
                'suspended',
                'Suspended',
                'Your enrolment is suspended. Contact support if you need help.',
                'none',
                null,
                'warning',
            ),
            EnrolmentLifecycleStatus::WITHDRAWN => new LearnerStatusView(
                'withdrawn',
                'Withdrawn',
                'This enrolment has been withdrawn.',
                'none',
                null,
                'muted',
            ),
            EnrolmentLifecycleStatus::CANCELLED => new LearnerStatusView(
                'cancelled',
                'Cancelled',
                'This enrolment was cancelled.',
                'none',
                null,
                'muted',
            ),
            EnrolmentLifecycleStatus::REFUNDED => new LearnerStatusView(
                'refunded',
                'Refunded',
                'This enrolment has been refunded.',
                'none',
                null,
                'muted',
            ),
            EnrolmentLifecycleStatus::ACCESS_EXPIRED => new LearnerStatusView(
                'access_expired',
                'Access expired',
                'Course access for this enrolment has expired.',
                'none',
                null,
                'muted',
            ),
            default => new LearnerStatusView(
                $lifecycleStatus,
                'Enrolment update',
                'See your enrolment for details.',
                'none',
                null,
                'muted',
            ),
        };
    }
}
