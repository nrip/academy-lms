<?php

declare(strict_types=1);

namespace Academy\Application\Assessments;

use Academy\Domain\Assessments\AssessmentAttemptQuestionRepository;
use Academy\Domain\Assessments\AssessmentAttemptRepository;
use Academy\Domain\Assessments\AssessmentResponseRepository;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use DateTimeImmutable;
use DateTimeZone;

final class SaveAssessmentResponsesService
{
    public function __construct(
        private readonly AssessmentAttemptAccessGuard $access,
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptQuestionRepository $attemptQuestions,
        private readonly AssessmentResponseRepository $responses,
    ) {
    }

    /**
     * @param array<string, mixed> $answersMap attempt_question_id => selected_option_id|null|''
     */
    public function save(AuthContext $auth, int $attemptId, array $answersMap): void
    {
        $attempt = $this->attempts->findById($attemptId);
        if ($attempt === null) {
            throw new NotFoundException('Assessment attempt not found.');
        }
        $this->access->requireOwnedEnrolmentForAttempt($auth, $attempt->enrolmentId);

        if (!$attempt->isInProgress()) {
            throw new ConflictException('Only in-progress attempts can be updated.');
        }

        $questions = $this->attemptQuestions->listByAttemptId($attemptId);
        $byId = [];
        foreach ($questions as $question) {
            $byId[$question->attemptQuestionId] = $question;
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($answersMap as $attemptQuestionIdRaw => $selectedRaw) {
            $attemptQuestionId = (int) $attemptQuestionIdRaw;
            if (!isset($byId[$attemptQuestionId])) {
                throw new ValidationException('Unknown attempt question in answers.');
            }
            $question = $byId[$attemptQuestionId];
            $selectedOptionId = null;
            if ($selectedRaw !== null && $selectedRaw !== '') {
                $selectedOptionId = (int) $selectedRaw;
                $allowed = array_map(
                    static fn (array $opt): int => $opt['option_id'],
                    $question->options,
                );
                if (!in_array($selectedOptionId, $allowed, true)) {
                    throw new ValidationException('Selected option is not part of the attempt snapshot.');
                }
            }
            $this->responses->upsertSelection($attemptId, $attemptQuestionId, $selectedOptionId, $at);
        }
    }
}
