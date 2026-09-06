<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\CourseVersionStateMachine;
use Academy\Domain\Courses\CourseVersionStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use PHPUnit\Framework\TestCase;

final class CourseVersionStateMachineTest extends TestCase
{
    public function testAllowedPairsSucceed(): void
    {
        $sm = new CourseVersionStateMachine();
        foreach (CourseVersionStateMachine::allowedPairs() as [$from, $to]) {
            $sm->assertCanTransition($from, $to);
            self::assertTrue(true);
        }
    }

    public function testDisallowedPairsFail(): void
    {
        $sm = new CourseVersionStateMachine();
        foreach (CourseVersionStateMachine::disallowedPairs() as [$from, $to]) {
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
        $sm = new CourseVersionStateMachine();
        $this->expectException(ConflictException::class);
        $sm->assertCanTransition(CourseVersionStatus::DRAFT, CourseVersionStatus::DRAFT);
    }

    public function testDraftToPublishedIsAllowed(): void
    {
        (new CourseVersionStateMachine())->assertCanTransition(
            CourseVersionStatus::DRAFT,
            CourseVersionStatus::PUBLISHED,
        );
        self::assertTrue(true);
    }
}
