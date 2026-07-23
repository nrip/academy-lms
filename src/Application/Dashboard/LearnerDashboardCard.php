<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

/**
 * One dashboard card combining Application + latest Payment + Enrolment (if any).
 */
final class LearnerDashboardCard
{
    /**
     * @param array{label: string, href: string}|null $primaryAction
     */
    public function __construct(
        public readonly int $applicationId,
        public readonly string $applicationNumber,
        public readonly string $courseTitle,
        public readonly string $batchName,
        public readonly string $applicationStatus,
        public readonly LearnerStatusView $applicationPresentation,
        public readonly ?string $submittedAt,
        public readonly ?string $reviewedAt,
        public readonly ?string $admittedAtApplication,
        public readonly ?int $paymentId,
        public readonly ?string $paymentStatus,
        public readonly ?string $paymentAmountDisplay,
        public readonly ?string $paymentCurrency,
        public readonly ?LearnerStatusView $paymentPresentation,
        public readonly bool $paymentRetryAllowed,
        public readonly ?int $enrolmentId,
        public readonly ?string $enrolmentReference,
        public readonly ?string $enrolmentLifecycleStatus,
        public readonly ?LearnerStatusView $enrolmentPresentation,
        public readonly ?string $enrolmentAdmittedAt,
        public readonly ?string $batchStartsAt,
        public readonly ?string $batchEndsAt,
        public readonly ?string $courseVersionLabel,
        public readonly ?array $primaryAction,
    ) {
    }
}
