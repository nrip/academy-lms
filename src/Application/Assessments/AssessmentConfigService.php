<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Application\Audit\AuditService;
use Academy\Application\Courses\CourseAdminAccessGuard;
use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentQuestionLink;
use Academy\Domain\Assessments\AssessmentQuestionLinkRepository;
use Academy\Domain\Assessments\AssessmentRepository;
use Academy\Domain\Assessments\Question;
use Academy\Domain\Assessments\QuestionBankRepository;
use Academy\Domain\Assessments\QuestionRepository;
use Academy\Domain\Assessments\QuestionStatus;
use Academy\Domain\Audit\AssessmentsAuditPayload;
use Academy\Domain\Courses\ContentItem;
use Academy\Domain\Courses\ContentItemContext;
use Academy\Domain\Courses\ContentItemRepository;
use Academy\Domain\Courses\ContentItemType;
use Academy\Domain\Courses\Course;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;

final class AssessmentConfigService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly ContentItemRepository $contentItems,
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentQuestionLinkRepository $links,
        private readonly QuestionBankRepository $banks,
        private readonly QuestionRepository $questions,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{
     *   course: Course,
     *   content: ContentItem,
     *   context: ContentItemContext,
     *   assessment: ?Assessment,
     *   links: list<AssessmentQuestionLink>,
     *   linked_questions: list<Question>,
     *   bank_questions: list<Question>,
     *   editable: bool
     * }
     */
    public function getForContentItem(AuthContext $auth, int $contentId): array
    {
        $at = $this->access->nowUtc();
        $context = $this->requireMcqContent($contentId);
        $course = $this->access->requireCourseInScope($auth, $context->courseId, $at);
        $this->access->requirePermission($auth, 'assessment.manage');
        $this->access->requireVersionViewable($auth, $context->courseId, $context->courseVersionId, $at);

        $assessment = $this->assessments->findByContentId($contentId);
        $links = $assessment === null ? [] : $this->links->listByAssessmentId($assessment->assessmentId);
        $linkedQuestions = [];
        foreach ($links as $link) {
            $question = $this->questions->findById($link->questionId);
            if ($question !== null) {
                $linkedQuestions[] = $question;
            }
        }

        $bankQuestions = [];
        $bank = $this->banks->findByCourseId($context->courseId);
        if ($bank !== null) {
            foreach ($this->questions->listByBankId($bank->bankId) as $question) {
                if ($question->status === QuestionStatus::ACTIVE) {
                    $bankQuestions[] = $question;
                }
            }
        }

        return [
            'course' => $course,
            'content' => $context->contentItem,
            'context' => $context,
            'assessment' => $assessment,
            'links' => $links,
            'linked_questions' => $linkedQuestions,
            'bank_questions' => $bankQuestions,
            'editable' => !$context->versionLocked,
        ];
    }

    /**
     * Upserts assessment config and replaces linked questions in one transaction.
     *
     * @param array<string, mixed> $input
     */
    public function save(AuthContext $auth, int $contentId, array $input): Assessment
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $context = $this->requireMcqContent($contentId);
        $this->access->requireVersionMutableWithPermission(
            $auth,
            $context->courseId,
            $context->courseVersionId,
            'assessment.manage',
            $at,
        );

        $fields = $this->normalizeConfig($input);
        $questionIds = $this->normalizeQuestionIds($input);
        $this->assertQuestionsBelongToCourseBank($context->courseId, $questionIds);
        if (count($questionIds) < $fields['questions_per_attempt']) {
            throw new ValidationException(
                'Select at least ' . (string) $fields['questions_per_attempt'] . ' question(s) from the bank.',
            );
        }

        $existing = $this->assessments->findByContentId($contentId);
        $previousLinkCount = 0;
        if ($existing !== null) {
            $previousLinkCount = count($this->links->listByAssessmentId($existing->assessmentId));
        }
        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            if ($existing === null) {
                $assessmentId = $this->assessments->insert([
                    'content_id' => $contentId,
                    'title' => $fields['title'],
                    'questions_per_attempt' => $fields['questions_per_attempt'],
                    'pass_threshold_percent' => $fields['pass_threshold_percent'],
                    'time_limit_seconds' => null,
                    'max_attempts' => $fields['max_attempts'],
                    'cooldown_seconds' => null,
                    'randomise_questions' => false,
                    'randomise_options' => false,
                ]);
            } else {
                $assessmentId = $existing->assessmentId;
                $updated = $this->assessments->update($assessmentId, [
                    'title' => $fields['title'],
                    'questions_per_attempt' => $fields['questions_per_attempt'],
                    'pass_threshold_percent' => $fields['pass_threshold_percent'],
                    'time_limit_seconds' => null,
                    'max_attempts' => $fields['max_attempts'],
                    'cooldown_seconds' => null,
                    'randomise_questions' => false,
                    'randomise_options' => false,
                ]);
                if (!$updated) {
                    throw new ConflictException(
                        'This CourseVersion is locked and immutable. Create Version N+1 to make changes.',
                    );
                }
            }

            $this->links->deleteByAssessmentId($assessmentId);
            $sequence = 1;
            foreach ($questionIds as $questionId) {
                $this->links->insert([
                    'assessment_id' => $assessmentId,
                    'question_id' => $questionId,
                    'sequence' => $sequence,
                ]);
                $sequence++;
            }

            $this->audit->record(
                new AssessmentsAuditPayload(
                    action: $existing === null ? 'assessment.created' : 'assessment.updated',
                    entityType: 'assessment',
                    entityId: (string) $assessmentId,
                    previous: $existing === null ? [] : [
                        'assessment_id' => $existing->assessmentId,
                        'title' => $existing->title,
                        'questions_per_attempt' => $existing->questionsPerAttempt,
                        'pass_threshold_percent' => $existing->passThresholdPercent,
                        'max_attempts' => $existing->maxAttempts,
                        'linked_question_count' => $previousLinkCount,
                    ],
                    next: [
                        'assessment_id' => $assessmentId,
                        'content_id' => $contentId,
                        'course_id' => $context->courseId,
                        'title' => $fields['title'],
                        'questions_per_attempt' => $fields['questions_per_attempt'],
                        'pass_threshold_percent' => $fields['pass_threshold_percent'],
                        'max_attempts' => $fields['max_attempts'],
                        'linked_question_count' => count($questionIds),
                    ],
                ),
                actorType: 'user',
                actorUserId: $actorUserId,
                source: 'course_admin',
            );

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $saved = $this->assessments->findById($assessmentId);
        if ($saved === null) {
            throw new ConflictException('Assessment could not be loaded after save.');
        }

        return $saved;
    }

    private function requireMcqContent(int $contentId): ContentItemContext
    {
        $context = $this->contentItems->findContextById($contentId);
        if ($context === null) {
            throw new NotFoundException('Content item not found.');
        }
        if ($context->contentItem->contentType !== ContentItemType::MCQ_ASSESSMENT) {
            throw new ValidationException('Assessments can only be configured on mcq_assessment content items.');
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   title: string,
     *   questions_per_attempt: int,
     *   pass_threshold_percent: string,
     *   max_attempts: int
     * }
     */
    private function normalizeConfig(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('Assessment title is required.');
        }
        if (mb_strlen($title) > 255) {
            throw new ValidationException('Assessment title must be 255 characters or fewer.');
        }

        $questionsPerAttempt = (int) ($input['questions_per_attempt'] ?? 0);
        if ($questionsPerAttempt < 1) {
            throw new ValidationException('Number of questions per attempt must be at least 1.');
        }

        $passRaw = trim((string) ($input['pass_threshold_percent'] ?? ''));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $passRaw) || (float) $passRaw > 100) {
            throw new ValidationException('Passing percentage must be between 0 and 100.');
        }
        $pass = number_format((float) $passRaw, 2, '.', '');

        $maxAttempts = (int) ($input['max_attempts'] ?? 0);
        if ($maxAttempts < 1) {
            throw new ValidationException('Maximum attempts must be at least 1.');
        }

        return [
            'title' => $title,
            'questions_per_attempt' => $questionsPerAttempt,
            'pass_threshold_percent' => $pass,
            'max_attempts' => $maxAttempts,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<int>
     */
    private function normalizeQuestionIds(array $input): array
    {
        $raw = $input['question_ids'] ?? [];
        if (!is_array($raw)) {
            throw new ValidationException('Select at least one question from the bank.');
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id < 1) {
                continue;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @param list<int> $questionIds
     */
    private function assertQuestionsBelongToCourseBank(int $courseId, array $questionIds): void
    {
        $bank = $this->banks->findByCourseId($courseId);
        if ($bank === null) {
            throw new ValidationException('This course has no question bank. Create questions first.');
        }

        foreach ($questionIds as $questionId) {
            $question = $this->questions->findById($questionId);
            if ($question === null || $question->bankId !== $bank->bankId) {
                throw new ValidationException('One or more selected questions are not in this course bank.');
            }
            if ($question->status !== QuestionStatus::ACTIVE) {
                throw new ValidationException('Only active bank questions can be linked to an assessment.');
            }
        }
    }
}
