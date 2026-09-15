<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Application\Security\RateLimiter;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\CourseAdminScopeAssignmentRepository;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\LearningQuestionBodyPolicy;
use Academy\Domain\Learning\LearningQuestionRepository;
use Academy\Domain\Learning\ModuleReleasePolicy;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class AskLearningQuestionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly ContentItemRepository $contentItems,
        private readonly ModuleRepository $modules,
        private readonly ContentProgressRepository $progress,
        private readonly LearningQuestionRepository $questions,
        private readonly CourseAdminScopeAssignmentRepository $scopes,
        private readonly PlayerAccessPolicy $accessPolicy,
        private readonly ModuleReleasePolicy $releasePolicy,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
        private readonly TransactionManager $transactions,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function ask(AuthContext $auth, int $enrolmentId, int $contentId, string $body): int
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.question.create_own');
        $this->rateLimiter->hit('learning.question.create', [
            ['type' => 'user', 'value' => (string) $userId],
        ]);

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
            throw new NotFoundException('Lesson not found.');
        }
        if ($target->contentType === ContentItemType::MCQ_ASSESSMENT) {
            throw new DomainRuleException('Questions cannot be asked on a quiz lesson.');
        }

        $module = null;
        foreach ($modules as $candidate) {
            if ($candidate->moduleId === $target->moduleId) {
                $module = $candidate;
                break;
            }
        }
        if ($module === null) {
            throw new NotFoundException('Lesson not found.');
        }

        $progressByContentId = [];
        foreach ($this->progress->listByEnrolmentId($enrolmentId) as $row) {
            $progressByContentId[$row->contentId] = $row;
        }
        if (!$this->releasePolicy->isContentAccessible($target, $modules, $items, $progressByContentId)) {
            throw new ConflictException('This content is locked until prior mandatory items are completed.');
        }

        $normalized = LearningQuestionBodyPolicy::normalize($body);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->transactions->run(function () use (
            $enrolment,
            $target,
            $module,
            $userId,
            $normalized,
            $now,
        ): int {
            $questionId = $this->questions->insert([
                'enrolment_id' => $enrolment->enrolmentId,
                'content_id' => $target->contentId,
                'course_id' => $enrolment->courseId,
                'course_version_id' => $enrolment->courseVersionId,
                'batch_id' => $enrolment->batchId,
                'module_id' => $module->moduleId,
                'asked_by_user_id' => $userId,
                'body' => $normalized,
                'asked_at' => $now,
            ]);

            $recipientIds = $this->scopes->listActiveAdminUserIdsForCourse($enrolment->courseId, $now);
            foreach ($recipientIds as $recipientUserId) {
                $this->outbox->enqueue(
                    TransactionalNotificationEventTypes::QUESTION_ASKED,
                    'learning_question',
                    (string) $questionId,
                    [
                        'question_id' => $questionId,
                        'enrolment_id' => $enrolment->enrolmentId,
                        'content_id' => $target->contentId,
                        'course_id' => $enrolment->courseId,
                        'recipient_user_id' => $recipientUserId,
                    ],
                    TransactionalNotificationEventTypes::QUESTION_ASKED . ':' . $questionId . ':' . $recipientUserId,
                );
            }

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'learning.question.asked',
                    entityType: 'learning_question',
                    entityId: (string) $questionId,
                    next: [
                        'enrolment_id' => $enrolment->enrolmentId,
                        'content_id' => $target->contentId,
                        'course_id' => $enrolment->courseId,
                        'user_id' => $userId,
                        'status' => 'open',
                        'question_id' => $questionId,
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'learning_qa',
            );

            return $questionId;
        });
    }

    private function requireUser(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
