<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Application\Certificates\CertificateIssuanceService;
use Academy\Domain\Assessments\Assessment;
use Academy\Domain\Assessments\AssessmentAttempt;
use Academy\Domain\Assessments\AssessmentAttemptQuestionRepository;
use Academy\Domain\Assessments\AssessmentAttemptRepository;
use Academy\Domain\Assessments\AssessmentRepository;
use Academy\Domain\Assessments\AssessmentResponseRepository;
use Academy\Domain\Certificates\CertificateRepository;
use Academy\Domain\Certificates\CertificateType;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Security\AuthContext;

final class AssessmentAttemptQueryService
{
    public function __construct(
        private readonly AssessmentAttemptAccessGuard $access,
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptQuestionRepository $attemptQuestions,
        private readonly AssessmentResponseRepository $responses,
        private readonly AssessmentRepository $assessments,
        private readonly CertificateIssuanceService $certificateIssuance,
        private readonly CertificateRepository $certificates,
    ) {
    }

    public function getAttemptView(AuthContext $auth, int $attemptId): AssessmentAttemptView
    {
        $attempt = $this->attempts->findById($attemptId);
        if ($attempt === null) {
            throw new NotFoundException('Assessment attempt not found.');
        }
        $this->access->requireOwnedEnrolmentForAttempt($auth, $attempt->enrolmentId);

        $assessment = $this->assessments->findById($attempt->assessmentId);
        if ($assessment === null) {
            throw new NotFoundException('Assessment not found.');
        }

        $questions = $this->attemptQuestions->listByAttemptId($attemptId);
        $responses = $this->responses->listByAttemptId($attemptId);
        $byQuestion = [];
        foreach ($responses as $response) {
            $byQuestion[$response->attemptQuestionId] = $response;
        }

        $showResults = $attempt->isSubmitted();
        $questionViews = [];
        foreach ($questions as $question) {
            $response = $byQuestion[$question->attemptQuestionId] ?? null;
            $questionViews[] = new AssessmentAttemptQuestionView(
                question: $question,
                selectedOptionId: $response?->selectedOptionId,
                isCorrect: $showResults ? $response?->isCorrect : null,
                marksAwarded: $showResults ? $response?->marksAwarded : null,
                revealCorrectOptionIds: $showResults ? $question->correctOptionIds : [],
            );
        }

        $completionMessage = null;
        $certificateId = null;
        $certificatesListUrl = null;
        if ($showResults) {
            $certificatesListUrl = '/learning/enrolments/' . $attempt->enrolmentId . '/certificates';
            if ($attempt->passedFlag) {
                $certificate = $this->certificateIssuance->issueCompletionIfEligible(
                    $attempt->enrolmentId,
                    $auth->userId,
                );
                if ($certificate === null) {
                    $certificate = $this->certificates->findCurrentByEnrolmentAndType(
                        $attempt->enrolmentId,
                        CertificateType::COMPLETION,
                    );
                }
                if ($certificate !== null) {
                    $certificateId = $certificate->certificateId;
                    $completionMessage = 'Assessment passed. Your course completion certificate is ready.';
                } else {
                    $completionMessage = 'Assessment passed. Complete any remaining mandatory lessons, then open Certificates from the course outline. If a certificate does not appear, add your certificate name in your learner profile.';
                }
            } else {
                $completionMessage = 'Assessment not passed. You can return to the outline and try again if attempts remain.';
            }
        }

        return new AssessmentAttemptView(
            attempt: $attempt,
            assessment: $assessment,
            questions: $questionViews,
            showResults: $showResults,
            completionMessage: $completionMessage,
            certificateId: $certificateId,
            certificatesListUrl: $certificatesListUrl,
        );
    }

    public function findInProgressAttemptId(AuthContext $auth, int $enrolmentId, int $assessmentId): ?int
    {
        $this->access->requireOwnedEnrolmentForAttempt($auth, $enrolmentId);
        $existing = $this->attempts->findInProgress($assessmentId, $enrolmentId);

        return $existing?->attemptId;
    }

    /**
     * @return array{assessment: ?Assessment, in_progress: ?AssessmentAttempt, attempts_used: int, max_attempts: int}
     */
    public function summaryForContent(
        AuthContext $auth,
        int $enrolmentId,
        int $contentId,
    ): array {
        $this->access->requireOwnedEnrolmentForAttempt($auth, $enrolmentId);
        $assessment = $this->assessments->findByContentId($contentId);
        if ($assessment === null) {
            return [
                'assessment' => null,
                'in_progress' => null,
                'attempts_used' => 0,
                'max_attempts' => 0,
            ];
        }

        return [
            'assessment' => $assessment,
            'in_progress' => $this->attempts->findInProgress($assessment->assessmentId, $enrolmentId),
            'attempts_used' => $this->attempts->countSubmittedOrTimedOut($assessment->assessmentId, $enrolmentId),
            'max_attempts' => $assessment->maxAttempts,
        ];
    }
}
