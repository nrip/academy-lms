<?php

declare(strict_types=1);

namespace Academy\Application\Certificates;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Certificates\Certificate;
use Academy\Domain\Certificates\CertificateEventRepository;
use Academy\Domain\Certificates\CertificateLearnerNameResolver;
use Academy\Domain\Certificates\CertificateRepository;
use Academy\Domain\Certificates\CertificateStatus;
use Academy\Domain\Certificates\CertificateType;
use Academy\Domain\Certificates\CompletionEligibilityPolicy;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class CertificateIssuanceService
{
    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly ContentItemRepository $contentItems,
        private readonly ContentProgressRepository $progress,
        private readonly CourseRepository $courses,
        private readonly CourseVersionRepository $courseVersions,
        private readonly LearnerProfileRepository $profiles,
        private readonly CertificateRepository $certificates,
        private readonly CertificateEventRepository $events,
        private readonly CompletionEligibilityPolicy $eligibility,
        private readonly CertificateLearnerNameResolver $nameResolver,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Idempotent: returns existing current certificate or issues one when eligible.
     * Returns null when not yet eligible or learner name cannot be resolved.
     */
    public function issueCompletionIfEligible(int $enrolmentId, ?int $actorUserId = null): ?Certificate
    {
        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            return null;
        }

        $existing = $this->certificates->findCurrentByEnrolmentAndType(
            $enrolmentId,
            CertificateType::COMPLETION,
        );
        if ($existing !== null) {
            return $existing;
        }

        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $progressMap = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $progressMap[$row->contentId] = $row;
        }
        if (!$this->eligibility->isEligible($items, $progressMap)) {
            return null;
        }

        $profile = $this->profiles->findByUserId($enrolment->userId);
        $learnerName = $this->nameResolver->resolve($profile);
        if ($learnerName === null) {
            return null;
        }

        $course = $this->courses->findById($enrolment->courseId);
        $version = $this->courseVersions->findById($enrolment->courseVersionId);
        if ($course === null || $version === null) {
            return null;
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $number = $this->allocateNumber();
        $hash = bin2hex(random_bytes(16));
        $pdo = $this->connections->connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $certificateId = 0;
        try {
            $again = $this->certificates->findCurrentByEnrolmentAndType(
                $enrolmentId,
                CertificateType::COMPLETION,
            );
            if ($again !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return $again;
            }

            $certificateId = $this->certificates->insertCurrent([
                'enrolment_id' => $enrolmentId,
                'course_version_id' => $enrolment->courseVersionId,
                'certificate_type' => CertificateType::COMPLETION,
                'certificate_number' => $number,
                'verification_hash' => $hash,
                'learner_name_snapshot' => $learnerName,
                'course_title_snapshot' => $course->masterTitle,
                'version_title_snapshot' => $version->title,
                'certificate_label' => $version->certificateType !== ''
                    ? $version->certificateType
                    : 'Certificate of Completion',
                'status' => CertificateStatus::ACTIVE,
                'issued_at' => $at,
            ]);

            $this->events->append($certificateId, 'issued', $actorUserId, 'completion_eligible', $at);

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'certificate.issued',
                    entityType: 'certificate',
                    entityId: (string) $certificateId,
                    next: [
                        'certificate_id' => $certificateId,
                        'enrolment_id' => $enrolmentId,
                        'course_version_id' => $enrolment->courseVersionId,
                        'certificate_type' => CertificateType::COMPLETION,
                        'certificate_number' => $number,
                        'user_id' => $enrolment->userId,
                        'status' => CertificateStatus::ACTIVE,
                    ],
                ),
                actorType: $actorUserId !== null ? 'user' : 'system',
                actorUserId: $actorUserId,
                source: 'certificate_issuance',
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate')) {
                return $this->certificates->findCurrentByEnrolmentAndType(
                    $enrolmentId,
                    CertificateType::COMPLETION,
                );
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->certificates->findById($certificateId);
    }

    public function requireOwnedEnrolment(AuthContext $auth, int $enrolmentId): void
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }
        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        if (!$enrolment->belongsToUser($auth->userId)) {
            throw new AuthorizationException('Enrolment is not owned by the authenticated user.');
        }
    }

    private function allocateNumber(): string
    {
        return 'ACAD-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
