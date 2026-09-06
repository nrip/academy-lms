<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Application\Audit\AuditService;
use Academy\Application\Courses\CourseAdminAccessGuard;
use Academy\Domain\Assessments\McqQuestionValidator;
use Academy\Domain\Assessments\Question;
use Academy\Domain\Assessments\QuestionBank;
use Academy\Domain\Assessments\QuestionBankRepository;
use Academy\Domain\Assessments\QuestionOption;
use Academy\Domain\Assessments\QuestionOptionRepository;
use Academy\Domain\Assessments\QuestionRepository;
use Academy\Domain\Assessments\QuestionStatus;
use Academy\Domain\Assessments\QuestionType;
use Academy\Domain\Audit\AssessmentsAuditPayload;
use Academy\Domain\Courses\Course;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use PDOException;

final class QuestionBankService
{
    public function __construct(
        private readonly CourseAdminAccessGuard $access,
        private readonly QuestionBankRepository $banks,
        private readonly QuestionRepository $questions,
        private readonly QuestionOptionRepository $options,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Ensures a course has exactly one question bank (demo: one bank per course).
     */
    public function ensureBankForCourse(int $courseId, string $courseTitle): QuestionBank
    {
        $existing = $this->banks->findByCourseId($courseId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $bankId = $this->banks->insert($courseId, $courseTitle . ' — Question bank');
        } catch (PDOException $exception) {
            $existing = $this->banks->findByCourseId($courseId);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }

        $bank = $this->banks->findById($bankId);
        if ($bank === null) {
            throw new ConflictException('Question bank could not be loaded after create.');
        }

        return $bank;
    }

    /**
     * @return array{
     *   course: Course,
     *   bank: QuestionBank,
     *   questions: list<array{question: Question, options: list<QuestionOption>}>
     * }
     */
    public function getBankForCourse(AuthContext $auth, int $courseId): array
    {
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $this->access->requirePermission($auth, 'question_bank.manage');

        $bank = $this->ensureBankForCourse($courseId, $course->masterTitle);
        $tree = [];
        foreach ($this->questions->listByBankId($bank->bankId) as $question) {
            $tree[] = [
                'question' => $question,
                'options' => $this->options->listByQuestionId($question->questionId),
            ];
        }

        return [
            'course' => $course,
            'bank' => $bank,
            'questions' => $tree,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createQuestion(AuthContext $auth, int $courseId, array $input): Question
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $this->access->requirePermission($auth, 'question_bank.manage');
        $bank = $this->ensureBankForCourse($courseId, $course->masterTitle);

        $fields = $this->normalizeQuestion($input, incrementVersionFrom: null);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $questionId = $this->questions->insert([
                'bank_id' => $bank->bankId,
                'question_type' => $fields['question_type'],
                'stem' => $fields['stem'],
                'marks' => $fields['marks'],
                'explanation' => $fields['explanation'],
                'version' => 1,
                'status' => $fields['status'],
            ]);
            $this->replaceOptions($questionId, $fields['options']);

            $this->audit->record(
                new AssessmentsAuditPayload(
                    action: 'question.created',
                    entityType: 'question',
                    entityId: (string) $questionId,
                    next: [
                        'question_id' => $questionId,
                        'bank_id' => $bank->bankId,
                        'course_id' => $courseId,
                        'question_type' => $fields['question_type'],
                        'stem_length' => mb_strlen($fields['stem']),
                        'marks' => $fields['marks'],
                        'status' => $fields['status'],
                        'option_count' => count($fields['options']),
                        'correct_option_count' => 1,
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

        return $this->requireQuestion($questionId);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateQuestion(AuthContext $auth, int $courseId, int $questionId, array $input): Question
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $this->access->requirePermission($auth, 'question_bank.manage');
        $bank = $this->ensureBankForCourse($courseId, $course->masterTitle);

        $before = $this->requireQuestionInBank($questionId, $bank->bankId);
        // WP-L4: no assessment links yet. When WP-L5 links questions to locked assessments,
        // edits of snapshotted stems must create a new version row instead of in-place mutation.
        $fields = $this->normalizeQuestion($input, incrementVersionFrom: $before);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $updated = $this->questions->update($questionId, [
                'stem' => $fields['stem'],
                'marks' => $fields['marks'],
                'explanation' => $fields['explanation'],
                'status' => $fields['status'],
                'version' => $fields['version'],
            ]);
            if (!$updated) {
                throw new ConflictException('Question could not be updated.');
            }
            $this->options->deleteByQuestionId($questionId);
            $this->replaceOptions($questionId, $fields['options']);

            $this->audit->record(
                new AssessmentsAuditPayload(
                    action: 'question.updated',
                    entityType: 'question',
                    entityId: (string) $questionId,
                    previous: [
                        'question_id' => $before->questionId,
                        'stem_length' => mb_strlen($before->stem),
                        'marks' => $before->marks,
                        'status' => $before->status,
                        'version' => $before->version,
                    ],
                    next: [
                        'question_id' => $questionId,
                        'stem_length' => mb_strlen($fields['stem']),
                        'marks' => $fields['marks'],
                        'status' => $fields['status'],
                        'version' => $fields['version'],
                        'option_count' => count($fields['options']),
                        'correct_option_count' => 1,
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

        return $this->requireQuestion($questionId);
    }

    public function deleteQuestion(AuthContext $auth, int $courseId, int $questionId): void
    {
        $actorUserId = $this->access->requireUserId($auth);
        $at = $this->access->nowUtc();
        $course = $this->access->requireCourseInScope($auth, $courseId, $at);
        $this->access->requirePermission($auth, 'question_bank.manage');
        $bank = $this->ensureBankForCourse($courseId, $course->masterTitle);
        $before = $this->requireQuestionInBank($questionId, $bank->bankId);

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $this->options->deleteByQuestionId($questionId);
            if (!$this->questions->delete($questionId)) {
                throw new ConflictException('Question could not be deleted.');
            }

            $this->audit->record(
                new AssessmentsAuditPayload(
                    action: 'question.deleted',
                    entityType: 'question',
                    entityId: (string) $questionId,
                    previous: [
                        'question_id' => $before->questionId,
                        'bank_id' => $before->bankId,
                        'course_id' => $courseId,
                        'stem_length' => mb_strlen($before->stem),
                        'status' => $before->status,
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
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   question_type: string,
     *   stem: string,
     *   marks: string,
     *   explanation: ?string,
     *   status: string,
     *   version: int,
     *   options: list<array{option_text: string, is_correct: bool}>
     * }
     */
    private function normalizeQuestion(array $input, ?Question $incrementVersionFrom): array
    {
        $type = QuestionType::assertValid(
            trim((string) ($input['question_type'] ?? QuestionType::MCQ_SINGLE)),
        );
        $stem = trim((string) ($input['stem'] ?? ''));
        if ($stem === '') {
            throw new ValidationException('Question stem is required.');
        }
        if (mb_strlen($stem) > 10000) {
            throw new ValidationException('Question stem is too long.');
        }

        $marksRaw = trim((string) ($input['marks'] ?? '1'));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $marksRaw) || (float) $marksRaw <= 0) {
            throw new ValidationException('Marks must be a positive decimal amount.');
        }
        $marks = number_format((float) $marksRaw, 2, '.', '');

        $explanation = trim((string) ($input['explanation'] ?? ''));
        $explanationValue = $explanation === '' ? null : $explanation;

        $status = QuestionStatus::assertValid(
            trim((string) ($input['status'] ?? QuestionStatus::ACTIVE)),
        );

        $options = $this->parseOptions($input);
        McqQuestionValidator::assertValidOptions($options);

        $version = 1;
        if ($incrementVersionFrom !== null) {
            $stemChanged = $incrementVersionFrom->stem !== $stem;
            $version = $stemChanged
                ? $incrementVersionFrom->version + 1
                : $incrementVersionFrom->version;
        }

        return [
            'question_type' => $type,
            'stem' => $stem,
            'marks' => $marks,
            'explanation' => $explanationValue,
            'status' => $status,
            'version' => $version,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{option_text: string, is_correct: bool}>
     */
    private function parseOptions(array $input): array
    {
        $rawOptions = $input['options'] ?? null;
        if (!is_array($rawOptions)) {
            throw new ValidationException('Answer options are required.');
        }

        $correctIndex = isset($input['correct_option']) ? (int) $input['correct_option'] : -1;
        $parsed = [];
        foreach ($rawOptions as $index => $option) {
            if (!is_array($option) && !is_string($option)) {
                continue;
            }
            if (is_string($option)) {
                $text = $option;
                $isCorrect = $index === $correctIndex;
            } else {
                $text = (string) ($option['option_text'] ?? $option['text'] ?? '');
                $isCorrect = !empty($option['is_correct']) || $index === $correctIndex;
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $parsed[] = [
                'option_text' => $text,
                'is_correct' => $isCorrect,
            ];
        }

        return $parsed;
    }

    /**
     * @param list<array{option_text: string, is_correct: bool}> $options
     */
    private function replaceOptions(int $questionId, array $options): void
    {
        $sequence = 1;
        foreach ($options as $option) {
            $this->options->insert([
                'question_id' => $questionId,
                'sequence' => $sequence,
                'option_text' => $option['option_text'],
                'is_correct' => $option['is_correct'],
            ]);
            $sequence++;
        }
    }

    private function requireQuestion(int $questionId): Question
    {
        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new ConflictException('Question could not be loaded after save.');
        }

        return $question;
    }

    private function requireQuestionInBank(int $questionId, int $bankId): Question
    {
        $question = $this->questions->findById($questionId);
        if ($question === null || $question->bankId !== $bankId) {
            throw new NotFoundException('Question not found.');
        }

        return $question;
    }
}
