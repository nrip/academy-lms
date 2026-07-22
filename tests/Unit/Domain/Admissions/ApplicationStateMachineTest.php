<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Admissions;

use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationStatus;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function testAllowedTransitionsAreAccepted(string $from, string $to): void
    {
        $machine = new ApplicationStateMachine();

        $machine->assertCanTransition($from, $to, $this->actorRolesFor($from, $to), $this->reasonFor($to));

        self::assertTrue(true);
    }

    #[DataProvider('disallowedTransitions')]
    public function testDisallowedTransitionsAreRejected(string $from, string $to): void
    {
        $machine = new ApplicationStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition($from, $to, ['learner', 'system', 'reviewer', 'admin'], 'reason');
    }

    public function testSameStatusTransitionIsRejectedAsConflict(): void
    {
        $machine = new ApplicationStateMachine();

        $this->expectException(ConflictException::class);
        $machine->assertCanTransition(ApplicationStatus::DRAFT, ApplicationStatus::DRAFT, ['learner']);
    }

    public function testWrongActorForLearnerEdgeIsRejected(): void
    {
        $machine = new ApplicationStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(ApplicationStatus::DRAFT, ApplicationStatus::SUBMITTED, ['system']);
    }

    public function testWrongActorForSystemEdgeIsRejected(): void
    {
        $machine = new ApplicationStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(ApplicationStatus::SUBMITTED, ApplicationStatus::UNDER_REVIEW, ['learner']);
    }

    public function testWrongActorForReviewerEdgeIsRejected(): void
    {
        $machine = new ApplicationStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(ApplicationStatus::UNDER_REVIEW, ApplicationStatus::REJECTED, ['learner'], 'reason');
    }

    public function testRejectionWithoutReasonIsRejected(): void
    {
        $machine = new ApplicationStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(ApplicationStatus::UNDER_REVIEW, ApplicationStatus::REJECTED, ['reviewer']);
    }

    public function testAllowedPairsAndDisallowedPairsPartitionTheFullMatrix(): void
    {
        $allowed = ApplicationStateMachine::allowedPairs();
        $disallowed = ApplicationStateMachine::disallowedPairs();

        $expectedTotal = count(ApplicationStatus::ALL) * (count(ApplicationStatus::ALL) - 1);
        self::assertSame($expectedTotal, count($allowed) + count($disallowed));

        $allowedSet = array_map(static fn (array $pair): string => $pair[0] . '>' . $pair[1], $allowed);
        $disallowedSet = array_map(static fn (array $pair): string => $pair[0] . '>' . $pair[1], $disallowed);
        self::assertEmpty(array_intersect($allowedSet, $disallowedSet));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedTransitions(): array
    {
        return ApplicationStateMachine::allowedPairs();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function disallowedTransitions(): array
    {
        return ApplicationStateMachine::disallowedPairs();
    }

    /**
     * @return list<string>
     */
    private function actorRolesFor(string $from, string $to): array
    {
        $edge = $from . '>' . $to;

        $learnerEdges = [
            ApplicationStatus::DRAFT . '>' . ApplicationStatus::SUBMITTED,
            ApplicationStatus::DRAFT . '>' . ApplicationStatus::WITHDRAWN,
            ApplicationStatus::REJECTED . '>' . ApplicationStatus::WITHDRAWN,
        ];
        $systemEdges = [
            ApplicationStatus::SUBMITTED . '>' . ApplicationStatus::DOCUMENTS_INCOMPLETE,
            ApplicationStatus::SUBMITTED . '>' . ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::SUBMITTED . '>' . ApplicationStatus::PAYMENT_PENDING,
            ApplicationStatus::DOCUMENTS_INCOMPLETE . '>' . ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::RESUBMISSION_REQUESTED . '>' . ApplicationStatus::EXPIRED,
            ApplicationStatus::PAYMENT_PENDING . '>' . ApplicationStatus::AWAITING_VERIFICATION,
            ApplicationStatus::PAYMENT_PENDING . '>' . ApplicationStatus::ADMITTED,
        ];

        $systemOrReviewerEdges = [
            ApplicationStatus::RESUBMISSION_REQUESTED . '>' . ApplicationStatus::UNDER_REVIEW,
        ];

        if (in_array($edge, $systemOrReviewerEdges, true)) {
            return ['system'];
        }

        if (in_array($edge, $learnerEdges, true)) {
            return ['learner'];
        }
        if (in_array($edge, $systemEdges, true)) {
            return ['system'];
        }

        return ['reviewer'];
    }

    private function reasonFor(string $to): ?string
    {
        return $to === ApplicationStatus::REJECTED ? 'reason' : null;
    }
}
