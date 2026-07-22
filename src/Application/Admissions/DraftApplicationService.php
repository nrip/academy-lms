<?php

declare(strict_types=1);

namespace Academy\Application\Admissions;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Admissions\Application;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Audit\AdmissionsAuditPayload;
use Academy\Domain\Courses\BatchAvailabilityEvaluator;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Draft creation is an entity-factory operation (Application already exists
 * at status=draft), not a state-machine transition. Submit and every later
 * transition is out of scope for WP-02 — see WP02_IMPLEMENTATION_NOTE.md.
 */
final class DraftApplicationService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuthorizationService $authorization,
        private readonly BatchRepository $batches,
        private readonly CourseVersionRepository $versions,
        private readonly CourseRepository $courses,
        private readonly ApplicationRepository $applications,
        private readonly BatchAvailabilityEvaluator $availability,
        private readonly AuditService $audit,
    ) {
    }

    public function createDraft(AuthContext $auth, int $batchId): Application
    {
        $this->authorization->require($auth, 'application.create');
        $userId = $this->requireUserId($auth);

        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            throw new NotFoundException('Batch not found.');
        }

        $version = $this->versions->findById($batch->courseVersionId);
        if ($version === null) {
            throw new NotFoundException('Batch not found.');
        }

        $course = $this->courses->findById($version->courseId);
        if ($course === null) {
            throw new NotFoundException('Batch not found.');
        }

        $existing = $this->applications->findByUserAndBatch($userId, $batchId);
        if ($existing !== null) {
            return $this->resolveExisting($existing);
        }

        $availabilityResult = $this->availability->evaluate($course, $version, $batch);
        if (!$availabilityResult->selectable) {
            throw new DomainRuleException($availabilityResult->label());
        }

        return $this->transactions->run(function () use ($userId, $version, $batch, $course): Application {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            try {
                $application = $this->applications->insertDraft(
                    $userId,
                    $version->versionId,
                    $batch->batchId,
                    $now,
                );
            } catch (PDOException $exception) {
                if (!$this->isDuplicateKey($exception)) {
                    throw $exception;
                }

                $raced = $this->applications->findByUserAndBatch($userId, $batch->batchId);
                if ($raced === null) {
                    throw $exception;
                }

                return $this->resolveExisting($raced);
            }

            if ($version->lockedAt === null) {
                $this->versions->lock($version->versionId, 'application_referenced', $now);
            }

            $this->audit->record(
                new AdmissionsAuditPayload(
                    action: 'application.draft_created',
                    entityType: 'application',
                    entityId: (string) $application->applicationId,
                    next: [
                        'user_id' => $userId,
                        'application_id' => $application->applicationId,
                        'course_id' => $course->courseId,
                        'course_version_id' => $version->versionId,
                        'batch_id' => $batch->batchId,
                        'status' => $application->status,
                        'result' => 'ok',
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'admissions',
            );

            return $application;
        });
    }

    public function getOwn(AuthContext $auth, int $applicationId): Application
    {
        $this->authorization->require($auth, 'application.view_own');
        $userId = $this->requireUserId($auth);

        $application = $this->applications->findById($applicationId);
        if ($application === null || $application->userId !== $userId) {
            throw new NotFoundException('Application not found.');
        }

        return $application;
    }

    private function resolveExisting(Application $existing): Application
    {
        if ($existing->isDraft()) {
            return $existing;
        }

        throw new ConflictException('An application already exists for this batch.');
    }

    private function requireUserId(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
