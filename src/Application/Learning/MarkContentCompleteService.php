<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\Audit\AuditService;
use Academy\Application\Certificates\CertificateIssuanceService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Courses\ContentCompletionRule;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\ContentProgress;
use Academy\Domain\Learning\ContentProgressCompletionSource;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\ModuleReleasePolicy;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class MarkContentCompleteService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly ModuleRepository $modules,
        private readonly ContentItemRepository $contentItems,
        private readonly ContentProgressRepository $progress,
        private readonly PlayerAccessPolicy $accessPolicy,
        private readonly ModuleReleasePolicy $releasePolicy,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
        private readonly CertificateIssuanceService $certificates,
    ) {
    }

    public function markComplete(AuthContext $auth, int $enrolmentId, int $contentId): ContentProgress
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }
        $userId = $auth->userId;
        $this->authorization->require($auth, 'learning.content.access');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->accessPolicy->assertCanAccessContent($enrolment, $userId);

        $modules = $this->modules->listByCourseVersionId($enrolment->courseVersionId);
        $items = $this->contentItems->listByCourseVersionId($enrolment->courseVersionId);
        $target = null;
        foreach ($items as $item) {
            if ($item->contentId === $contentId) {
                $target = $item;
                break;
            }
        }
        if ($target === null) {
            throw new NotFoundException('Content item not found on this CourseVersion.');
        }

        if (!in_array($target->contentType, [ContentItemType::TEXT_LESSON, ContentItemType::PDF], true)) {
            throw new DomainRuleException('Only text lessons and PDFs can be marked complete here.');
        }
        if ($target->completionRule !== ContentCompletionRule::MARK_COMPLETE) {
            throw new DomainRuleException('This content item does not use mark-complete completion.');
        }

        $progressMap = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $progressMap[$row->contentId] = $row;
        }
        if (!$this->releasePolicy->isContentAccessible($target, $modules, $items, $progressMap)) {
            throw new ConflictException('This content is locked until prior mandatory items are completed.');
        }

        $existing = $progressMap[$contentId] ?? null;
        if ($existing !== null && $existing->isCompleted()) {
            return $existing;
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $updated = $this->progress->markCompleted(
                $enrolmentId,
                $contentId,
                ContentProgressCompletionSource::LEARNER,
                $at,
                $existing?->rowVersion,
            );

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'content_progress.completed',
                    entityType: 'content_progress',
                    entityId: (string) $updated->progressId,
                    previous: [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'completion_status' => $existing?->completionStatus
                            ?? ContentProgressCompletionStatus::NOT_STARTED,
                    ],
                    next: [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'progress_id' => $updated->progressId,
                        'completion_status' => ContentProgressCompletionStatus::COMPLETED,
                        'completion_source' => ContentProgressCompletionSource::LEARNER,
                        'course_version_id' => $enrolment->courseVersionId,
                        'user_id' => $userId,
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'learner_player',
            );

            $this->certificates->issueCompletionIfEligible($enrolmentId, $userId);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $updated;
    }
}
