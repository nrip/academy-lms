<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Application\Audit\AuditService;
use Academy\Domain\Assessments\AssessmentAttempt;
use Academy\Domain\Assessments\AssessmentAttemptQuestionRepository;
use Academy\Domain\Assessments\AssessmentAttemptRepository;
use Academy\Domain\Assessments\AssessmentAttemptStatus;
use Academy\Domain\Assessments\AssessmentAttemptStatusHistoryRepository;
use Academy\Domain\Assessments\AssessmentQuestionLinkRepository;
use Academy\Domain\Assessments\QuestionOptionRepository;
use Academy\Domain\Assessments\QuestionRepository;
use Academy\Domain\Assessments\QuestionStatus;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class StartAssessmentAttemptService
{
    public function __construct(
        private readonly AssessmentAttemptAccessGuard $access,
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptQuestionRepository $attemptQuestions,
        private readonly AssessmentQuestionLinkRepository $links,
        private readonly QuestionRepository $questions,
        private readonly QuestionOptionRepository $options,
        private readonly AssessmentAttemptStatusHistoryRepository $statusHistory,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
    ) {
    }

    public function start(AuthContext $auth, int $enrolmentId, int $assessmentId): AssessmentAttempt
    {
        $ctx = $this->access->requireAccessibleAssessment($auth, $enrolmentId, $assessmentId);
        $assessment = $ctx['assessment'];
        $enrolment = $ctx['enrolment'];
        $userId = $ctx['user_id'];

        $existing = $this->attempts->findInProgress($assessmentId, $enrolmentId);
        if ($existing !== null) {
            throw new ConflictException('An in-progress attempt already exists for this assessment.');
        }

        $finishedCount = $this->attempts->countSubmittedOrTimedOut($assessmentId, $enrolmentId);
        if ($finishedCount >= $assessment->maxAttempts) {
            throw new DomainRuleException('Maximum assessment attempts have been used.');
        }

        if ($assessment->cooldownSeconds !== null && $assessment->cooldownSeconds > 0) {
            $latest = $this->attempts->findLatestSubmitted($assessmentId, $enrolmentId);
            if ($latest !== null && $latest->submittedAt !== null) {
                $unlockAt = $latest->submittedAt->modify('+' . $assessment->cooldownSeconds . ' seconds');
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                if ($now < $unlockAt) {
                    throw new DomainRuleException('Cooldown is still active. Try again later.');
                }
            }
        }

        $links = $this->links->listByAssessmentId($assessmentId);
        if (count($links) < $assessment->questionsPerAttempt) {
            throw new ValidationException('Assessment is not ready: not enough linked questions.');
        }

        $selectedLinks = array_slice($links, 0, $assessment->questionsPerAttempt);
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        $attemptId = 0;
        try {
            $attemptNumber = $this->attempts->nextAttemptNumber($assessmentId, $enrolmentId);
            $attemptId = $this->attempts->insertInProgress([
                'assessment_id' => $assessmentId,
                'enrolment_id' => $enrolmentId,
                'content_id' => $assessment->contentId,
                'attempt_number' => $attemptNumber,
                'pass_threshold_percent' => $assessment->passThresholdPercent,
                'started_at' => $at,
                'deadline_at' => null,
            ]);

            $sequence = 1;
            foreach ($selectedLinks as $link) {
                $question = $this->questions->findById($link->questionId);
                if ($question === null || $question->status !== QuestionStatus::ACTIVE) {
                    throw new ValidationException('A linked question is missing or inactive.');
                }
                $options = $this->options->listByQuestionId($question->questionId);
                if ($options === []) {
                    throw new ValidationException('A linked question has no options.');
                }
                $optionPayload = [];
                $correctIds = [];
                foreach ($options as $option) {
                    $optionPayload[] = [
                        'option_id' => $option->optionId,
                        'sequence' => $option->sequence,
                        'option_text' => $option->optionText,
                    ];
                    if ($option->isCorrect) {
                        $correctIds[] = $option->optionId;
                    }
                }
                if ($correctIds === []) {
                    throw new ValidationException('A linked question has no correct option.');
                }

                $this->attemptQuestions->insert([
                    'attempt_id' => $attemptId,
                    'question_id' => $question->questionId,
                    'question_version' => $question->version,
                    'sequence' => $sequence,
                    'stem' => $question->stem,
                    'marks' => $question->marks,
                    'options' => $optionPayload,
                    'correct_option_ids' => $correctIds,
                ]);
                ++$sequence;
            }

            $this->statusHistory->append(
                $attemptId,
                null,
                AssessmentAttemptStatus::IN_PROGRESS,
                $userId,
                'attempt_started',
                $at,
            );

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'assessment_attempt.started',
                    entityType: 'assessment_attempt',
                    entityId: (string) $attemptId,
                    next: [
                        'attempt_id' => $attemptId,
                        'assessment_id' => $assessmentId,
                        'enrolment_id' => $enrolmentId,
                        'attempt_number' => $attemptNumber,
                        'user_id' => $userId,
                        'course_version_id' => $enrolment->courseVersionId,
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'learner_player',
            );

            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate')) {
                throw new ConflictException('An in-progress attempt already exists for this assessment.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $attempt = $this->attempts->findById($attemptId);
        if ($attempt === null) {
            throw new ConflictException('Attempt could not be reloaded after start.');
        }

        return $attempt;
    }
}
