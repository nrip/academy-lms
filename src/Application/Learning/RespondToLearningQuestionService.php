<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\Audit\AuditService;
use Academy\Application\Courses\CourseAdminAccessGuard;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\LearningQuestionBodyPolicy;
use Academy\Domain\Learning\LearningQuestionRepository;
use Academy\Domain\Learning\LearningQuestionResponseRepository;
use Academy\Domain\Learning\LearningQuestionStatus;
use Academy\Domain\Notifications\TransactionalNotificationEventTypes;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class RespondToLearningQuestionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly CourseAdminAccessGuard $access,
        private readonly LearningQuestionRepository $questions,
        private readonly LearningQuestionResponseRepository $responses,
        private readonly OutboxWriter $outbox,
        private readonly AuditService $audit,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function respond(AuthContext $auth, int $questionId, string $body): int
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.question.respond');

        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new NotFoundException('Question not found.');
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->access->requireCourseInScope($auth, $question->courseId, $at);
        LearningQuestionBodyPolicy::assertCanRespond($question);
        $normalized = LearningQuestionBodyPolicy::normalizeResponse($body);

        return $this->transactions->run(function () use ($question, $userId, $normalized, $at): int {
            $responseId = $this->responses->insert(
                $question->questionId,
                $userId,
                $normalized,
                $at,
            );

            $marked = $this->questions->markAnswered($question->questionId, $at);
            $previousStatus = $question->status;
            $nextStatus = $marked ? LearningQuestionStatus::ANSWERED : $question->status;

            $this->outbox->enqueue(
                TransactionalNotificationEventTypes::QUESTION_RESPONDED,
                'learning_question',
                (string) $question->questionId,
                [
                    'question_id' => $question->questionId,
                    'response_id' => $responseId,
                    'enrolment_id' => $question->enrolmentId,
                    'content_id' => $question->contentId,
                    'course_id' => $question->courseId,
                    'recipient_user_id' => $question->askedByUserId,
                ],
                TransactionalNotificationEventTypes::QUESTION_RESPONDED . ':' . $question->questionId . ':' . $responseId,
            );

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'learning.question.responded',
                    entityType: 'learning_question',
                    entityId: (string) $question->questionId,
                    previous: [
                        'status' => $previousStatus,
                    ],
                    next: [
                        'question_id' => $question->questionId,
                        'response_id' => $responseId,
                        'enrolment_id' => $question->enrolmentId,
                        'content_id' => $question->contentId,
                        'course_id' => $question->courseId,
                        'user_id' => $userId,
                        'status' => $nextStatus,
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'learning_qa',
            );

            return $responseId;
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
