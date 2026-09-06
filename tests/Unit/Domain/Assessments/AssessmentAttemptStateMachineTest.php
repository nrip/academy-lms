<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Assessments;

use Academy\Domain\Assessments\AssessmentAttemptStateMachine;
use Academy\Domain\Assessments\AssessmentAttemptStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use PHPUnit\Framework\TestCase;

final class AssessmentAttemptStateMachineTest extends TestCase
{
    public function testAllowedPairsSucceed(): void
    {
        $sm = new AssessmentAttemptStateMachine();
        foreach (AssessmentAttemptStateMachine::allowedPairs() as [$from, $to]) {
            $sm->assertCanTransition($from, $to);
            self::assertTrue(true);
        }
    }

    public function testDisallowedPairsFail(): void
    {
        $sm = new AssessmentAttemptStateMachine();
        foreach (AssessmentAttemptStateMachine::disallowedPairs() as [$from, $to]) {
            try {
                $sm->assertCanTransition($from, $to);
                self::fail(sprintf('Expected disallowed transition %s → %s', $from, $to));
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSameStatusIsConflict(): void
    {
        $sm = new AssessmentAttemptStateMachine();
        $this->expectException(ConflictException::class);
        $sm->assertCanTransition(
            AssessmentAttemptStatus::IN_PROGRESS,
            AssessmentAttemptStatus::IN_PROGRESS,
        );
    }
}
