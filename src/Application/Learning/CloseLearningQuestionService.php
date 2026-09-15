<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\Audit\AuditService;
use Academy\Application\Courses\CourseAdminAccessGuard;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\LearningQuestionBodyPolicy;
use Academy\Domain\Learning\LearningQuestionRepository;
use Academy\Domain\Learning\LearningQuestionStatus;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class CloseLearningQuestionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly CourseAdminAccessGuard $access,
        private readonly LearningQuestionRepository $questions,
        private readonly AuditService $audit,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function close(AuthContext $auth, int $questionId): void
    {
        $userId = $this->requireUser($auth);
        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new NotFoundException('Question not found.');
        }

        LearningQuestionBodyPolicy::assertCanClose($question);
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $asAsker = $question->belongsToAsker($userId);
        $asFaculty = false;
        if (!$asAsker) {
            $this->authorization->require($auth, 'learning.question.respond');
            $this->access->requireCourseInScope($auth, $question->courseId, $at);
            $asFaculty = true;
        } else {
            $this->authorization->require($auth, 'learning.question.view_own');
        }

        if (!$asAsker && !$asFaculty) {
            throw new AuthorizationException('Not allowed to close this question.');
        }

        $this->transactions->run(function () use ($question, $userId, $at): void {
            if (!$this->questions->markClosed($question->questionId, $userId, $at)) {
                throw new NotFoundException('Question not found.');
            }

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'learning.question.closed',
                    entityType: 'learning_question',
                    entityId: (string) $question->questionId,
                    previous: ['status' => $question->status],
                    next: [
                        'question_id' => $question->questionId,
                        'enrolment_id' => $question->enrolmentId,
                        'content_id' => $question->contentId,
                        'course_id' => $question->courseId,
                        'user_id' => $userId,
                        'status' => LearningQuestionStatus::CLOSED,
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'learning_qa',
            );
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
