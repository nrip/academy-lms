<?php

declare(strict_types=1);

namespace Academy\Domain\Assessments;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use DateTimeImmutable;

/**
 * AssessmentAttempt lifecycle (Phase 1 runtime). Timers optional — timed_out reserved.
 */
final class AssessmentAttemptStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        AssessmentAttemptStatus::IN_PROGRESS => [
            AssessmentAttemptStatus::SUBMITTED,
            AssessmentAttemptStatus::TIMED_OUT,
        ],
        AssessmentAttemptStatus::SUBMITTED => [],
        AssessmentAttemptStatus::TIMED_OUT => [],
    ];

    public function assertCanTransition(string $from, string $to): void
    {
        AssessmentAttemptStatus::assertValid($from);
        AssessmentAttemptStatus::assertValid($to);

        if ($from === $to) {
            throw new ConflictException('Assessment attempt is already in the requested status.');
        }

        $allowed = self::ALLOWED[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'Assessment attempt transition from %s to %s is not allowed.',
                $from,
                $to,
            ));
        }
    }

    public function transition(string $from, string $to, DateTimeImmutable $at): AssessmentAttemptTransitionResult
    {
        $this->assertCanTransition($from, $to);

        return new AssessmentAttemptTransitionResult($from, $to, $at);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedPairs(): array
    {
        $pairs = [];
        foreach (self::ALLOWED as $from => $tos) {
            foreach ($tos as $to) {
                $pairs[] = [$from, $to];
            }
        }

        return $pairs;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function disallowedPairs(): array
    {
        $disallowed = [];
        foreach (AssessmentAttemptStatus::ALL as $from) {
            foreach (AssessmentAttemptStatus::ALL as $to) {
                if ($from === $to) {
                    continue;
                }
                if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
                    $disallowed[] = [$from, $to];
                }
            }
        }

        return $disallowed;
    }
}
