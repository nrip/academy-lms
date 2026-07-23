<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDO;

/**
 * Read-only learner dashboard query. Scoped to authenticated user_id server-side.
 */
final class LearnerDashboardQueryService
{
    private const LIMIT = 50;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ConnectionFactory $connections,
        private readonly LearnerStatusPresenter $presenter,
    ) {
    }

    public function getDashboard(AuthContext $auth): LearnerDashboardView
    {
        $this->authorization->require($auth, 'dashboard.view_own');
        if (!$auth->authenticated || $auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }
        $userId = $auth->userId;

        $pdo = $this->connections->connection();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE user_id = :user_id');
        $countStmt->execute(['user_id' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT
                a.application_id,
                a.application_number,
                a.status AS application_status,
                a.submitted_at,
                a.updated_at AS application_updated_at,
                c.master_title AS course_title,
                cv.version_number,
                cv.title AS version_title,
                b.name AS batch_name,
                b.starts_at AS batch_starts_at,
                b.ends_at AS batch_ends_at,
                lp.payment_id,
                lp.status AS payment_status,
                lp.amount_minor,
                lp.currency AS payment_currency,
                e.enrolment_id,
                e.public_reference AS enrolment_reference,
                e.lifecycle_status AS enrolment_lifecycle_status,
                e.admitted_at AS enrolment_admitted_at
             FROM applications a
             INNER JOIN course_versions cv ON cv.version_id = a.course_version_id
             INNER JOIN courses c ON c.course_id = cv.course_id
             INNER JOIN batches b ON b.batch_id = a.batch_id
             LEFT JOIN enrolments e ON e.application_id = a.application_id AND e.user_id = a.user_id
             LEFT JOIN payments lp ON lp.payment_id = (
                 SELECT p.payment_id
                 FROM payments p
                 WHERE p.application_id = a.application_id
                 ORDER BY p.payment_id DESC
                 LIMIT 1
             )
             WHERE a.user_id = :user_id
             ORDER BY a.updated_at DESC, a.application_id DESC
             LIMIT ' . self::LIMIT,
        );
        $stmt->execute(['user_id' => $userId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cards = [];
        $requiredActions = [];
        $seenActions = [];

        foreach ($rows as $row) {
            if ((int) $row['application_id'] <= 0) {
                throw new AuthorizationException('Dashboard query returned invalid row.');
            }

            $appStatus = (string) $row['application_status'];
            $appPresentation = $this->presenter->applicationPresentation($appStatus);

            $paymentId = $row['payment_id'] !== null ? (int) $row['payment_id'] : null;
            $paymentStatus = $row['payment_status'] !== null ? (string) $row['payment_status'] : null;
            $paymentPresentation = $paymentStatus !== null
                ? $this->presenter->paymentPresentation($paymentStatus)
                : null;
            $retryAllowed = $paymentStatus !== null && PaymentStatus::isRetryEligible($paymentStatus)
                && $appStatus === \Academy\Domain\Admissions\ApplicationStatus::PAYMENT_PENDING;

            $enrolmentId = $row['enrolment_id'] !== null ? (int) $row['enrolment_id'] : null;
            $enrolmentStatus = $row['enrolment_lifecycle_status'] !== null
                ? (string) $row['enrolment_lifecycle_status']
                : null;
            $enrolmentPresentation = $enrolmentStatus !== null
                ? $this->presenter->enrolmentPresentation($enrolmentStatus)
                : null;

            $primaryAction = $this->resolvePrimaryAction(
                (int) $row['application_id'],
                $appPresentation,
                $paymentPresentation,
                $retryAllowed,
                $enrolmentId !== null,
            );

            if ($primaryAction !== null) {
                $actionKey = $primaryAction['href'];
                if (!isset($seenActions[$actionKey])) {
                    $seenActions[$actionKey] = true;
                    $requiredActions[] = [
                        'label' => $primaryAction['label'],
                        'href' => $primaryAction['href'],
                        'severity' => $appPresentation->severity,
                    ];
                }
            }

            $amountDisplay = null;
            if ($row['amount_minor'] !== null) {
                $amountDisplay = PaymentAmountSnapshot::minorToDecimal((int) $row['amount_minor']);
            }

            $cards[] = new LearnerDashboardCard(
                applicationId: (int) $row['application_id'],
                applicationNumber: (string) $row['application_number'],
                courseTitle: (string) $row['course_title'],
                batchName: (string) $row['batch_name'],
                applicationStatus: $appStatus,
                applicationPresentation: $appPresentation,
                submittedAt: $row['submitted_at'] !== null ? (string) $row['submitted_at'] : null,
                reviewedAt: null,
                admittedAtApplication: $enrolmentId !== null && $row['enrolment_admitted_at'] !== null
                    ? (string) $row['enrolment_admitted_at']
                    : null,
                paymentId: $paymentId,
                paymentStatus: $paymentStatus,
                paymentAmountDisplay: $amountDisplay,
                paymentCurrency: $row['payment_currency'] !== null ? (string) $row['payment_currency'] : null,
                paymentPresentation: $paymentPresentation,
                paymentRetryAllowed: $retryAllowed,
                enrolmentId: $enrolmentId,
                enrolmentReference: $row['enrolment_reference'] !== null ? (string) $row['enrolment_reference'] : null,
                enrolmentLifecycleStatus: $enrolmentStatus,
                enrolmentPresentation: $enrolmentPresentation,
                enrolmentAdmittedAt: $row['enrolment_admitted_at'] !== null
                    ? (string) $row['enrolment_admitted_at']
                    : null,
                batchStartsAt: $row['batch_starts_at'] !== null ? (string) $row['batch_starts_at'] : null,
                batchEndsAt: $row['batch_ends_at'] !== null ? (string) $row['batch_ends_at'] : null,
                courseVersionLabel: 'v' . (int) $row['version_number'] . ' — ' . (string) $row['version_title'],
                primaryAction: $primaryAction,
            );
        }

        return new LearnerDashboardView($cards, $requiredActions, $total);
    }

    /**
     * @return array{label: string, href: string}|null
     */
    private function resolvePrimaryAction(
        int $applicationId,
        LearnerStatusView $app,
        ?LearnerStatusView $payment,
        bool $retryAllowed,
        bool $hasEnrolment,
    ): ?array {
        if ($hasEnrolment) {
            return null;
        }

        return match ($app->nextActionCode) {
            'complete_application' => [
                'label' => 'Complete your application',
                'href' => '/applications/' . $applicationId,
            ],
            'upload_documents', 'correct_documents' => [
                'label' => (string) $app->nextActionLabel,
                'href' => '/applications/' . $applicationId . '/documents',
            ],
            'pay' => [
                'label' => 'Pay now',
                'href' => '/applications/' . $applicationId . '/payment',
            ],
            default => $retryAllowed
                ? [
                    'label' => 'Retry payment',
                    'href' => '/applications/' . $applicationId . '/payment',
                ]
                : ($payment !== null && $payment->nextActionCode === 'wait_confirmation'
                    ? [
                        'label' => 'View payment status',
                        'href' => '/applications/' . $applicationId . '/payment-result',
                    ]
                    : null),
        };
    }
}
