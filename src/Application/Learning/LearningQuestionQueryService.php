<?php

declare(strict_types=1);

namespace Academy\Application\Learning;

use Academy\Application\Courses\CourseAdminAccessGuard;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\ModuleRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\LearningQuestion;
use Academy\Domain\Learning\LearningQuestionRepository;
use Academy\Domain\Learning\LearningQuestionResponseRepository;
use Academy\Domain\Learning\LearningQuestionStatus;
use Academy\Domain\Learning\PlayerAccessPolicy;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;

final class LearningQuestionQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly CourseAdminAccessGuard $access,
        private readonly EnrolmentRepository $enrolments,
        private readonly LearningQuestionRepository $questions,
        private readonly LearningQuestionResponseRepository $responses,
        private readonly CourseRepository $courses,
        private readonly ModuleRepository $modules,
        private readonly PlayerAccessPolicy $accessPolicy,
        private readonly LearnerProfileRepository $profiles,
        private readonly ConnectionFactory $connections,
    ) {
    }

    /**
     * @return list<LearningQuestionThreadItemView>
     */
    public function lessonThread(AuthContext $auth, int $enrolmentId, int $contentId): array
    {
        $userId = $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.question.view_own');

        $enrolment = $this->enrolments->findById($enrolmentId);
        if ($enrolment === null) {
            throw new NotFoundException('Enrolment not found.');
        }
        $this->accessPolicy->assertCanAccessContent($enrolment, $userId);

        $threads = [];
        foreach ($this->questions->listForEnrolmentAndContent($enrolmentId, $contentId) as $question) {
            if (!$question->belongsToAsker($userId)) {
                continue;
            }
            $threads[] = $this->threadItem($question);
        }

        return $threads;
    }

    public function canAskOnLesson(string $contentType): bool
    {
        return $contentType !== ContentItemType::MCQ_ASSESSMENT;
    }

    /**
     * @param list<int> $courseIds
     * @return list<FacultyQuestionQueueItemView>
     */
    public function facultyQueue(AuthContext $auth, array $courseIds): array
    {
        $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.question.view_scoped');

        $items = [];
        foreach ($this->questions->listForCourses($courseIds, 40) as $question) {
            $items[] = $this->queueItem($question);
        }

        return $items;
    }

    public function facultyDetail(AuthContext $auth, int $questionId): FacultyQuestionDetailView
    {
        $this->requireUser($auth);
        $this->authorization->require($auth, 'learning.question.view_scoped');

        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new NotFoundException('Question not found.');
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->access->requireCourseInScope($auth, $question->courseId, $at);

        $labels = $this->contextLabels($question);
        $canRespond = !$question->isClosed() && $this->authorization->check($auth, 'learning.question.respond');

        return new FacultyQuestionDetailView(
            questionId: $question->questionId,
            courseTitle: $labels['course_title'],
            chapterTitle: $labels['chapter_title'],
            lessonTitle: $labels['lesson_title'],
            learnerName: $this->displayName($question->askedByUserId),
            body: $question->body,
            status: $question->status,
            statusLabel: $this->statusLabel($question->status),
            askedAt: $question->askedAt,
            canRespond: $canRespond,
            canClose: !$question->isClosed(),
            responses: $this->responseViews($question->questionId),
        );
    }

    private function threadItem(LearningQuestion $question): LearningQuestionThreadItemView
    {
        return new LearningQuestionThreadItemView(
            questionId: $question->questionId,
            body: $question->body,
            status: $question->status,
            statusLabel: $this->statusLabel($question->status),
            askedAt: $question->askedAt,
            canClose: !$question->isClosed(),
            responses: $this->responseViews($question->questionId),
        );
    }

    private function queueItem(LearningQuestion $question): FacultyQuestionQueueItemView
    {
        $labels = $this->contextLabels($question);

        return new FacultyQuestionQueueItemView(
            questionId: $question->questionId,
            courseTitle: $labels['course_title'],
            chapterTitle: $labels['chapter_title'],
            lessonTitle: $labels['lesson_title'],
            learnerName: $this->displayName($question->askedByUserId),
            status: $question->status,
            statusLabel: $this->statusLabel($question->status),
            askedAt: $question->askedAt,
        );
    }

    /**
     * @return list<LearningQuestionResponseItemView>
     */
    private function responseViews(int $questionId): array
    {
        $views = [];
        foreach ($this->responses->listForQuestion($questionId) as $response) {
            $views[] = new LearningQuestionResponseItemView(
                responseId: $response->responseId,
                body: $response->body,
                responderName: $this->displayName($response->respondedByUserId),
                respondedAt: $response->respondedAt,
            );
        }

        return $views;
    }

    /**
     * @return array{course_title: string, chapter_title: string, lesson_title: string}
     */
    private function contextLabels(LearningQuestion $question): array
    {
        $course = $this->courses->findById($question->courseId);
        $module = $this->modules->findById($question->moduleId);
        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT title FROM content_items WHERE content_id = :id LIMIT 1');
        $stmt->execute(['id' => $question->contentId]);
        $lessonTitle = (string) ($stmt->fetchColumn() ?: 'Lesson');

        return [
            'course_title' => $course?->masterTitle ?? 'Course',
            'chapter_title' => $module?->title ?? 'Chapter',
            'lesson_title' => $lessonTitle !== '' ? $lessonTitle : 'Lesson',
        ];
    }

    private function displayName(int $userId): string
    {
        $profile = $this->profiles->findByUserId($userId);
        if ($profile !== null && $profile->preferredDisplayName !== null && trim($profile->preferredDisplayName) !== '') {
            return trim($profile->preferredDisplayName);
        }

        $pdo = $this->connections->connection();
        $stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $email = (string) ($stmt->fetchColumn() ?: '');

        return $email !== '' ? $email : 'Learner';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            LearningQuestionStatus::OPEN => 'Waiting for a response',
            LearningQuestionStatus::ANSWERED => 'Responded',
            LearningQuestionStatus::CLOSED => 'Closed',
            default => $status,
        };
    }

    private function requireUser(AuthContext $auth): int
    {
        if ($auth->userId === null) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth->userId;
    }
}
