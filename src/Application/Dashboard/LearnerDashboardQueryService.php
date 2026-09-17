<?php

declare(strict_types=1);

namespace Academy\Application\Dashboard;

use Academy\Application\Learning\LearnerPlayerQueryService;
use Academy\Application\Learning\LearningQuestionQueryService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Certificates\CertificateRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Notifications\InAppNotificationRepository;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Payments\PaymentStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
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
        private readonly LearnerPlayerQueryService $player,
        private readonly CertificateRepository $certificates,
        private readonly InAppNotificationRepository $inbox,
        private readonly LearnerProfileRepository $learnerProfiles,
        private readonly LearningQuestionQueryService $questions,
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
                c.slug AS course_slug,
                CASE WHEN c.cover_object_key IS NOT NULL AND CHAR_LENGTH(c.cover_object_key) > 0 THEN 1 ELSE 0 END AS has_cover,
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
        $studyCards = [];
        $upcomingSessions = [];
        $requiredActions = [];
        $seenActions = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

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

            $study = $enrolmentId !== null
                ? $this->studyCard(
                    $auth,
                    $enrolmentId,
                    (string) $row['course_title'],
                    (string) $row['course_slug'],
                    (string) $row['has_cover'] === '1',
                    (string) $row['batch_name'],
                    $enrolmentPresentation,
                    $enrolmentStatus,
                    $now,
                    $upcomingSessions,
                )
                : null;
            if ($study !== null) {
                $studyCards[] = $study;
            }

            $primaryAction = $this->resolvePrimaryAction(
                (int) $row['application_id'],
                $appPresentation,
                $paymentPresentation,
                $retryAllowed,
                $enrolmentId !== null,
                $enrolmentId,
                $enrolmentStatus,
                $study,
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
                courseVersionLabel: 'Edition ' . (int) $row['version_number'] . ' — ' . (string) $row['version_title'],
                primaryAction: $primaryAction,
            );
        }

        usort(
            $upcomingSessions,
            static fn (LearnerUpcomingSession $left, LearnerUpcomingSession $right): int => $left->startsAt <=> $right->startsAt,
        );

        $recentUnread = [];
        foreach ($this->inbox->listForUser($userId, 20) as $update) {
            if ($update->isRead()) {
                continue;
            }
            $recentUnread[] = $update;
            if (count($recentUnread) === 3) {
                break;
            }
        }

        $certificateSummaries = [];
        $totalActiveCertificates = 0;
        foreach ($studyCards as $study) {
            if ($study->certificateCount <= 0) {
                continue;
            }
            $totalActiveCertificates += $study->certificateCount;
            $certificateSummaries[] = [
                'courseTitle' => $study->courseTitle,
                'certificateCount' => $study->certificateCount,
                'href' => $study->certificatesHref,
            ];
        }

        return new LearnerDashboardView(
            $cards,
            $requiredActions,
            $total,
            $studyCards,
            array_slice($upcomingSessions, 0, 5),
            $this->inbox->countUnread($userId),
            $recentUnread,
            $this->shouldShowProfileWelcome($userId),
            $certificateSummaries,
            $totalActiveCertificates,
        );
    }

    private function shouldShowProfileWelcome(int $userId): bool
    {
        $profile = $this->learnerProfiles->findByUserId($userId);
        if ($profile === null) {
            return true;
        }

        $first = $profile->firstName !== null ? trim($profile->firstName) : '';
        $display = $profile->preferredDisplayName !== null ? trim($profile->preferredDisplayName) : '';

        return $first === '' && $display === '';
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
        ?int $enrolmentId,
        ?string $enrolmentLifecycleStatus,
        ?LearnerStudyCard $study,
    ): ?array {
        if ($hasEnrolment && $enrolmentId !== null
            && $enrolmentLifecycleStatus === EnrolmentLifecycleStatus::ACTIVE
        ) {
            return [
                'label' => 'Continue learning',
                'href' => $study !== null ? $study->continueHref : '/learning/enrolments/' . $enrolmentId,
            ];
        }

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

    /**
     * @param list<LearnerUpcomingSession> $upcomingSessions
     */
    private function studyCard(
        AuthContext $auth,
        int $enrolmentId,
        string $courseTitle,
        string $courseSlug,
        bool $hasCover,
        string $batchName,
        ?LearnerStatusView $enrolmentPresentation,
        ?string $enrolmentStatus,
        DateTimeImmutable $now,
        array &$upcomingSessions,
    ): LearnerStudyCard {
        $outlineHref = '/learning/enrolments/' . $enrolmentId;
        $coverPath = $hasCover && $courseSlug !== ''
            ? '/courses/' . rawurlencode($courseSlug) . '/cover'
            : null;
        $completed = 0;
        $total = 0;
        $percent = 0;
        $continueTitle = null;
        $continueChapter = null;
        $continueChapterIndex = null;
        $chapterTotal = 0;
        $continueHref = $outlineHref;
        $accessible = $enrolmentStatus === EnrolmentLifecycleStatus::ACTIVE;
        $openQuestions = 0;
        $answeredQuestions = 0;

        if ($enrolmentStatus === EnrolmentLifecycleStatus::ACTIVE
            || $enrolmentStatus === EnrolmentLifecycleStatus::SCHEDULED
        ) {
            try {
                $outline = $this->player->outline($auth, $enrolmentId);
                $completed = $outline->completedCount;
                $total = $outline->totalCount;
                $percent = $outline->progressPercent();
                $accessible = $outline->contentAccessible;
                $chapterTotal = $outline->chapterTotal();
                $continue = $outline->continueTarget();
                if ($continue !== null) {
                    $continueTitle = $continue['title'];
                    $continueChapter = $continue['chapterTitle'];
                    $continueChapterIndex = $continue['chapterIndex'];
                    $continueHref = $outlineHref . '/items/' . $continue['contentId'];
                }
                if ($outline->hasCover && $outline->courseSlug !== '') {
                    $coverPath = '/courses/' . rawurlencode($outline->courseSlug) . '/cover';
                }
                foreach ($outline->upcomingLiveSessions($now) as $session) {
                    $upcomingSessions[] = new LearnerUpcomingSession(
                        courseTitle: $outline->courseTitle,
                        lessonTitle: $session['title'],
                        chapterTitle: $session['chapterTitle'],
                        startsAt: $session['startsAt'],
                        href: $outlineHref . '/items/' . $session['contentId'],
                    );
                }
            } catch (AuthorizationException | DomainRuleException | NotFoundException) {
                $accessible = false;
            }

            try {
                $qa = $this->questions->enrolmentStatusSummary($auth, $enrolmentId);
                $openQuestions = $qa['open'];
                $answeredQuestions = $qa['answered'];
            } catch (AuthorizationException | NotFoundException | ConflictException | DomainRuleException) {
                // Q&A is optional on the dashboard when access is not ready.
            }
        }

        $certificateCount = $this->certificateCount($enrolmentId);

        return new LearnerStudyCard(
            enrolmentId: $enrolmentId,
            courseTitle: $courseTitle,
            batchName: $batchName,
            statusLabel: $enrolmentPresentation !== null ? $enrolmentPresentation->label : 'Enrolled',
            statusExplanation: $enrolmentPresentation !== null ? $enrolmentPresentation->explanation : '',
            statusSeverity: $enrolmentPresentation !== null ? $enrolmentPresentation->severity : 'secondary',
            contentAccessible: $accessible,
            coverPath: $coverPath,
            completedCount: $completed,
            totalCount: $total,
            progressPercent: $percent,
            progressNarrative: LearnerProgressNarrative::forStudyCard(
                $completed,
                $total,
                $percent,
                $continueTitle,
                $continueChapter,
                $continueChapterIndex,
                $chapterTotal,
                $accessible,
                $certificateCount,
            ),
            continueTitle: $continueTitle,
            continueChapterTitle: $continueChapter,
            continueChapterIndex: $continueChapterIndex,
            chapterTotal: $chapterTotal,
            continueHref: $continueHref,
            certificateCount: $certificateCount,
            certificatesHref: $outlineHref . '/certificates',
            openQuestionCount: $openQuestions,
            answeredQuestionCount: $answeredQuestions,
        );
    }

    private function certificateCount(int $enrolmentId): int
    {
        $count = 0;
        foreach ($this->certificates->listByEnrolmentId($enrolmentId) as $certificate) {
            if ($certificate->isActive()) {
                ++$count;
            }
        }

        return $count;
    }
}
