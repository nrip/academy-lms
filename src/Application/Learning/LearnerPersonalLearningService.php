<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\Audit\AuditService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\LearnerEnrolmentGoal;
use Academy\Domain\Learning\LearnerEnrolmentGoalRepository;
use Academy\Domain\Learning\LearnerGoalPolicy;
use Academy\Domain\Learning\LearnerLessonBookmark;
use Academy\Domain\Learning\LearnerLessonBookmarkRepository;
use Academy\Domain\Learning\LearnerLessonNote;
use Academy\Domain\Learning\LearnerLessonNoteRepository;
use Academy\Domain\Learning\LearnerNoteBodyPolicy;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Learner-owned bookmarks, notes, and goals. Never touches completion or release.
 */
final class LearnerPersonalLearningService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EnrolmentRepository $enrolments,
        private readonly ContentItemRepository $contentItems,
        private readonly LearnerLessonBookmarkRepository $bookmarks,
        private readonly LearnerLessonNoteRepository $notes,
        private readonly LearnerEnrolmentGoalRepository $goals,
        private readonly PlayerAccessPolicy $accessPolicy,
        private readonly AuditService $audit,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function bookmarkForLesson(AuthContext $auth, int $enrolmentId, int $contentId): ?LearnerLessonBookmark
    {
        $this->authorization->require($auth, 'learning.bookmark.manage_own');
        $this->requireOwnedLessonAccess($auth, $enrolmentId, $contentId, forContent: false);

        return $this->bookmarks->findForEnrolmentAndContent($enrolmentId, $contentId);
    }

    /**
     * @return list<array{content_id: int, title: string, created_at: DateTimeImmutable}>
     */
    public function bookmarksForEnrolment(AuthContext $auth, int $enrolmentId): array
    {
        $this->authorization->require($auth, 'learning.bookmark.manage_own');
        $enrolment = $this->requireOwnedEnrolment($auth, $enrolmentId, forContent: false);
        $titles = [];
        foreach ($this->contentItems->listByCourseVersionId($enrolment->courseVersionId) as $item) {
            $titles[$item->contentId] = $item->title;
        }
        $rows = [];
        foreach ($this->bookmarks->listForEnrolment($enrolmentId) as $bookmark) {
            if (!isset($titles[$bookmark->contentId])) {
                continue;
            }
            $rows[] = [
                'content_id' => $bookmark->contentId,
                'title' => $titles[$bookmark->contentId],
                'created_at' => $bookmark->createdAt,
            ];
        }

        return $rows;
    }

    public function addBookmark(AuthContext $auth, int $enrolmentId, int $contentId): void
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.bookmark.manage_own');
        $this->requireOwnedLessonAccess($auth, $enrolmentId, $contentId, forContent: true);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->transactions->run(function () use ($enrolmentId, $contentId, $userId, $now): void {
            $bookmarkId = $this->bookmarks->upsert($enrolmentId, $contentId, $userId, $now);
            $this->auditPersonal(
                $userId,
                new LearningAuditPayload(
                    action: 'learning.bookmark.added',
                    entityType: 'learner_lesson_bookmark',
                    entityId: (string) $bookmarkId,
                    next: [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'user_id' => $userId,
                        'bookmark_id' => $bookmarkId,
                    ],
                ),
            );
        });
    }

    public function removeBookmark(AuthContext $auth, int $enrolmentId, int $contentId): void
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.bookmark.manage_own');
        $this->requireOwnedLessonAccess($auth, $enrolmentId, $contentId, forContent: true);
        $existing = $this->bookmarks->findForEnrolmentAndContent($enrolmentId, $contentId);
        if ($existing === null) {
            return;
        }

        $this->transactions->run(function () use ($enrolmentId, $contentId, $userId, $existing): void {
            $this->bookmarks->deleteForEnrolmentAndContent($enrolmentId, $contentId, $userId);
            $this->auditPersonal(
                $userId,
                new LearningAuditPayload(
                    action: 'learning.bookmark.removed',
                    entityType: 'learner_lesson_bookmark',
                    entityId: (string) $existing->bookmarkId,
                    previous: [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'user_id' => $userId,
                        'bookmark_id' => $existing->bookmarkId,
                    ],
                ),
            );
        });
    }

    public function noteForLesson(AuthContext $auth, int $enrolmentId, int $contentId): ?LearnerLessonNote
    {
        $this->authorization->require($auth, 'learning.note.manage_own');
        $this->requireOwnedLessonAccess($auth, $enrolmentId, $contentId, forContent: false);

        return $this->notes->findForEnrolmentAndContent($enrolmentId, $contentId);
    }

    public function saveNote(AuthContext $auth, int $enrolmentId, int $contentId, string $body): void
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.note.manage_own');
        $this->requireOwnedLessonAccess($auth, $enrolmentId, $contentId, forContent: true);
        $normalized = LearnerNoteBodyPolicy::normalize($body);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $previous = $this->notes->findForEnrolmentAndContent($enrolmentId, $contentId);

        $this->transactions->run(function () use ($enrolmentId, $contentId, $userId, $normalized, $now, $previous): void {
            $noteId = $this->notes->upsert($enrolmentId, $contentId, $userId, $normalized, $now);
            $this->auditPersonal(
                $userId,
                new LearningAuditPayload(
                    action: $previous === null ? 'learning.note.created' : 'learning.note.updated',
                    entityType: 'learner_lesson_note',
                    entityId: (string) $noteId,
                    previous: $previous === null ? [] : [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'user_id' => $userId,
                        'note_id' => $previous->noteId,
                        'body_length' => mb_strlen($previous->body),
                    ],
                    next: [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'user_id' => $userId,
                        'note_id' => $noteId,
                        'body_length' => mb_strlen($normalized),
                    ],
                ),
            );
        });
    }

    public function deleteNote(AuthContext $auth, int $enrolmentId, int $contentId): void
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.note.manage_own');
        $this->requireOwnedLessonAccess($auth, $enrolmentId, $contentId, forContent: true);
        $existing = $this->notes->findForEnrolmentAndContent($enrolmentId, $contentId);
        if ($existing === null) {
            return;
        }

        $this->transactions->run(function () use ($enrolmentId, $contentId, $userId, $existing): void {
            $this->notes->deleteForEnrolmentAndContent($enrolmentId, $contentId, $userId);
            $this->auditPersonal(
                $userId,
                new LearningAuditPayload(
                    action: 'learning.note.deleted',
                    entityType: 'learner_lesson_note',
                    entityId: (string) $existing->noteId,
                    previous: [
                        'enrolment_id' => $enrolmentId,
                        'content_id' => $contentId,
                        'user_id' => $userId,
                        'note_id' => $existing->noteId,
                        'body_length' => mb_strlen($existing->body),
                    ],
                ),
            );
        });
    }

    public function goalForEnrolment(AuthContext $auth, int $enrolmentId): ?LearnerEnrolmentGoal
    {
        $this->authorization->require($auth, 'learning.goal.manage_own');
        $this->requireOwnedEnrolment($auth, $enrolmentId, forContent: false);

        return $this->goals->findForEnrolment($enrolmentId);
    }

    public function saveGoal(AuthContext $auth, int $enrolmentId, string $label, string $targetDate): void
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.goal.manage_own');
        $this->requireOwnedEnrolment($auth, $enrolmentId, forContent: false);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $normalized = LearnerGoalPolicy::normalize($label, $targetDate, $now);
        $previous = $this->goals->findForEnrolment($enrolmentId);

        $this->transactions->run(function () use ($enrolmentId, $userId, $normalized, $now, $previous): void {
            $goalId = $this->goals->upsert(
                $enrolmentId,
                $userId,
                $normalized['label'],
                $normalized['target_date'],
                $now,
            );
            $this->auditPersonal(
                $userId,
                new LearningAuditPayload(
                    action: $previous === null ? 'learning.goal.created' : 'learning.goal.updated',
                    entityType: 'learner_enrolment_goal',
                    entityId: (string) $goalId,
                    previous: $previous === null ? [] : [
                        'enrolment_id' => $enrolmentId,
                        'user_id' => $userId,
                        'goal_id' => $previous->goalId,
                        'target_date' => $previous->targetDate,
                    ],
                    next: [
                        'enrolment_id' => $enrolmentId,
                        'user_id' => $userId,
                        'goal_id' => $goalId,
                        'target_date' => $normalized['target_date'],
                    ],
                ),
            );
        });
    }

    public function clearGoal(AuthContext $auth, int $enrolmentId): void
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.goal.manage_own');
        $this->requireOwnedEnrolment($auth, $enrolmentId, forContent: false);
        $existing = $this->goals->findForEnrolment($enrolmentId);
        if ($existing === null) {
            return;
        }

        $this->transactions->run(function () use ($enrolmentId, $userId, $existing): void {
            $this->goals->deleteForEnrolment($enrolmentId, $userId);
            $this->auditPersonal(
                $userId,
                new LearningAuditPayload(
                    action: 'learning.goal.cleared',
                    entityType: 'learner_enrolment_goal',
                    entityId: (string) $existing->goalId,
                    previous: [
                        'enrolment_id' => $enrolmentId,
                        'user_id' => $userId,
                        'goal_id' => $existing->goalId,
                        'target_date' => $existing->targetDate,
                    ],
                ),
            );
        });
    }

    private function auditPersonal(int $userId, LearningAuditPayload $payload): void
    {
        $this->audit->record(
            $payload,
            actorType: 'user',
            actorUserId: $userId,
            source: 'learner_personal',
        );
    }

    private function requireOwnedLessonAccess(AuthContext $auth, int $enrolmentId, int $contentId, bool $forContent): void
    {
        $enrolment = $this->requireOwnedEnrolment($auth, $enrolmentId, $forContent);
        $found = false;
        foreach ($this->contentItems->listByCourseVersionId($enrolment->courseVersionId) as $item) {
            if ($item->contentId === $contentId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new NotFoundException('Lesson not found.');
        }
    }

    private function requireOwnedEnrolment(AuthContext $auth, int $enrolmentId, bool $forContent): \Academy\Domain\Learning\Enrolment
    {
        $userId = $this->requireUser($auth);
        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        if ($forContent) {
            $this->accessPolicy->assertCanAccessContent($enrolment, $userId);
        } else {
            $this->accessPolicy->assertCanViewOutline($enrolment, $userId);
        }

        return $enrolment;
    }

    private function requireUser(AuthContext $auth): int
    {
        if (!$auth->authenticated || $auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
