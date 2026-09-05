<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Application\Audit\AuditService;
use Academy\Application\Certificates\CertificateIssuanceService;
use Academy\Domain\Assessments\AssessmentAttempt;
use Academy\Domain\Assessments\AssessmentAttemptQuestionRepository;
use Academy\Domain\Assessments\AssessmentAttemptRepository;
use Academy\Domain\Assessments\AssessmentAttemptStateMachine;
use Academy\Domain\Assessments\AssessmentAttemptStatus;
use Academy\Domain\Assessments\AssessmentAttemptStatusHistoryRepository;
use Academy\Domain\Assessments\AssessmentResponseRepository;
use Academy\Domain\Assessments\AttemptScoringService;
use Academy\Domain\Audit\LearningAuditPayload;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Learning\ContentProgressCompletionSource;
use Academy\Domain\Learning\ContentProgressCompletionStatus;
use Academy\Domain\Learning\ContentProgressRepository;
use Academy\Domain\Security\AuthContext;
use Academy\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class SubmitAssessmentAttemptService
{
    public function __construct(
        private readonly AssessmentAttemptAccessGuard $access,
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptQuestionRepository $attemptQuestions,
        private readonly AssessmentResponseRepository $responses,
        private readonly AssessmentAttemptStateMachine $stateMachine,
        private readonly AttemptScoringService $scoring,
        private readonly AssessmentAttemptStatusHistoryRepository $statusHistory,
        private readonly ContentProgressRepository $progress,
        private readonly ConnectionFactory $connections,
        private readonly AuditService $audit,
        private readonly CertificateIssuanceService $certificates,
    ) {
    }

    public function submit(AuthContext $auth, int $attemptId): AssessmentAttempt
    {
        $attempt = $this->attempts->findById($attemptId);
        if ($attempt === null) {
            throw new NotFoundException('Assessment attempt not found.');
        }
        $owned = $this->access->requireOwnedEnrolmentForAttempt($auth, $attempt->enrolmentId);
        $userId = $owned['user_id'];

        if (!$attempt->isInProgress()) {
            throw new ConflictException('Only in-progress attempts can be submitted.');
        }

        $questions = $this->attemptQuestions->listByAttemptId($attemptId);
        $responseList = $this->responses->listByAttemptId($attemptId);
        $byQuestion = [];
        foreach ($responseList as $response) {
            $byQuestion[$response->attemptQuestionId] = $response;
        }

        $result = $this->scoring->score($questions, $byQuestion, $attempt->passThresholdPercent);
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->stateMachine->assertCanTransition(
            AssessmentAttemptStatus::IN_PROGRESS,
            AssessmentAttemptStatus::SUBMITTED,
        );

        $pdo = $this->connections->connection();
        $pdo->beginTransaction();
        try {
            $this->responses->applyScores($attemptId, $result['per_question']);

            if (!$this->attempts->markSubmitted($attemptId, [
                'score_percent' => $result['score_percent'],
                'marks_awarded' => $result['marks_awarded'],
                'marks_available' => $result['marks_available'],
                'passed_flag' => $result['passed'],
                'submitted_at' => $at,
            ], $attempt->rowVersion)) {
                throw new ConflictException('Attempt was updated concurrently. Refresh and try again.');
            }

            $this->statusHistory->append(
                $attemptId,
                AssessmentAttemptStatus::IN_PROGRESS,
                AssessmentAttemptStatus::SUBMITTED,
                $userId,
                $result['passed'] ? 'submitted_passed' : 'submitted_failed',
                $at,
            );

            if ($result['passed']) {
                $existingProgress = $this->progress->findByEnrolmentAndContent(
                    $attempt->enrolmentId,
                    $attempt->contentId,
                );
                $this->progress->markCompleted(
                    $attempt->enrolmentId,
                    $attempt->contentId,
                    ContentProgressCompletionSource::ASSESSMENT,
                    $at,
                    $existingProgress?->rowVersion,
                );
            }

            $this->audit->record(
                new LearningAuditPayload(
                    action: 'assessment_attempt.submitted',
                    entityType: 'assessment_attempt',
                    entityId: (string) $attemptId,
                    previous: [
                        'attempt_id' => $attemptId,
                        'completion_status' => ContentProgressCompletionStatus::IN_PROGRESS,
                    ],
                    next: [
                        'attempt_id' => $attemptId,
                        'assessment_id' => $attempt->assessmentId,
                        'enrolment_id' => $attempt->enrolmentId,
                        'score_percent' => $result['score_percent'],
                        'passed_flag' => $result['passed'] ? 1 : 0,
                        'user_id' => $userId,
                        'completion_source' => $result['passed']
                            ? ContentProgressCompletionSource::ASSESSMENT
                            : null,
                    ],
                ),
                actorType: 'user',
                actorUserId: $userId,
                source: 'learner_player',
            );

            $this->certificates->issueCompletionIfEligible($attempt->enrolmentId, $userId);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $submitted = $this->attempts->findById($attemptId);
        if ($submitted === null) {
            throw new ConflictException('Submitted attempt could not be reloaded.');
        }

        return $submitted;
    }
}
