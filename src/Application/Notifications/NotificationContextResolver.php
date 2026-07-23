<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\Dashboard\LearnerStatusPresenter;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Notifications\NotificationFailureCategory;
use Academy\Domain\Outbox\OutboxMessage;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

/**
 * Builds allow-listed template variables from authoritative DB state + outbox payload IDs.
 */
final class NotificationContextResolver
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly ApplicationRepository $applications,
        private readonly PaymentRepository $payments,
        private readonly EnrolmentRepository $enrolments,
        private readonly NotificationRecipientResolver $recipients,
        private readonly string $appUrl,
        private readonly LearnerStatusPresenter $statusPresenter,
    ) {
    }

    public function tryResolveUserId(OutboxMessage $message): ?int
    {
        $payload = $message->payload;
        $applicationId = isset($payload['application_id']) ? (int) $payload['application_id'] : 0;
        $paymentId = isset($payload['payment_id']) ? (int) $payload['payment_id'] : 0;
        $enrolmentId = isset($payload['enrolment_id']) ? (int) $payload['enrolment_id'] : 0;

        if ($applicationId <= 0 && $paymentId > 0) {
            $payment = $this->payments->findById($paymentId);
            if ($payment !== null) {
                $applicationId = $payment->applicationId;
            }
        }
        if ($applicationId <= 0 && $enrolmentId > 0) {
            $enrolment = $this->enrolments->findById($enrolmentId);
            if ($enrolment !== null) {
                return $enrolment->userId;
            }
        }
        if ($applicationId <= 0) {
            return null;
        }
        $application = $this->applications->findById($applicationId);

        return $application?->userId;
    }

    /**
     * @return array{
     *   user_id: int,
     *   variables: array<string, string>,
     *   recipient: array{email: string, recipient_hash: string, recipient_masked: string, display_name: string}
     * }
     */
    public function resolve(OutboxMessage $message): array
    {
        $payload = $message->payload;
        $applicationId = isset($payload['application_id']) ? (int) $payload['application_id'] : 0;
        $paymentId = isset($payload['payment_id']) ? (int) $payload['payment_id'] : 0;
        $enrolmentId = isset($payload['enrolment_id']) ? (int) $payload['enrolment_id'] : 0;

        if ($applicationId <= 0 && $paymentId > 0) {
            $payment = $this->payments->findById($paymentId);
            if ($payment !== null) {
                $applicationId = $payment->applicationId;
            }
        }
        if ($applicationId <= 0 && $enrolmentId > 0) {
            $enrolment = $this->enrolments->findById($enrolmentId);
            if ($enrolment !== null) {
                $applicationId = $enrolment->applicationId;
            }
        }

        if ($applicationId <= 0) {
            throw new DomainRuleException(NotificationFailureCategory::CONTEXT_MISSING);
        }

        $application = $this->applications->findById($applicationId);
        if ($application === null) {
            throw new DomainRuleException(NotificationFailureCategory::CONTEXT_MISSING);
        }

        $labels = $this->loadCourseBatchLabels($application->courseVersionId, $application->batchId);
        $recipient = $this->recipients->resolveVerifiedEmail($application->userId);

        $statusLabel = $this->statusPresenter->applicationPresentation($application->status)->label;
        $safeReason = '';

        if ($paymentId > 0) {
            $payment = $this->payments->findById($paymentId);
            if ($payment !== null) {
                $statusLabel = $this->statusPresenter->paymentPresentation($payment->status)->label;
            }
        }
        if ($enrolmentId > 0) {
            $enrolment = $this->enrolments->findById($enrolmentId);
            if ($enrolment !== null) {
                $statusLabel = $this->statusPresenter->enrolmentPresentation($enrolment->lifecycleStatus)->label;
            }
        }

        if ($application->status === ApplicationStatus::REJECTED) {
            $safeReason = 'Please see your dashboard for next steps.';
        }
        if ($application->status === ApplicationStatus::RESUBMISSION_REQUESTED) {
            $safeReason = 'Please correct the requested documents and resubmit.';
        }
        if (isset($payload['status']) && is_string($payload['status'])) {
            // Prefer live DB status labels over raw payload strings.
            unset($payload);
        }

        $displayName = $recipient['display_name'];
        $profileName = $this->preferredDisplayName($application->userId);
        if ($profileName !== null && $profileName !== '') {
            $displayName = $profileName;
        }

        return [
            'user_id' => $application->userId,
            'recipient' => array_merge($recipient, ['display_name' => $displayName]),
            'variables' => [
                'learner_display_name' => $displayName,
                'application_number' => $application->applicationNumber,
                'course_title' => $labels['course_title'],
                'batch_name' => $labels['batch_name'],
                'status_label' => $statusLabel,
                'safe_reason' => $safeReason,
                'dashboard_link' => rtrim($this->appUrl, '/') . '/dashboard',
            ],
        ];
    }

    /**
     * @return array{course_title: string, batch_name: string}
     */
    private function loadCourseBatchLabels(int $courseVersionId, int $batchId): array
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT c.master_title AS course_title, b.name AS batch_name
             FROM course_versions cv
             INNER JOIN courses c ON c.course_id = cv.course_id
             INNER JOIN batches b ON b.batch_id = :batch_id
             WHERE cv.version_id = :version_id
             LIMIT 1',
        );
        $stmt->execute([
            'batch_id' => $batchId,
            'version_id' => $courseVersionId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new DomainRuleException(NotificationFailureCategory::CONTEXT_MISSING);
        }

        return [
            'course_title' => (string) $row['course_title'],
            'batch_name' => (string) $row['batch_name'],
        ];
    }

    private function preferredDisplayName(int $userId): ?string
    {
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare(
            'SELECT preferred_display_name FROM learner_profiles WHERE user_id = :user_id LIMIT 1',
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $name = trim((string) ($row['preferred_display_name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
