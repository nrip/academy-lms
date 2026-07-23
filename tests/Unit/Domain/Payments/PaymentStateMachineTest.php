<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Payments;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function testAllowedTransitionsAreAccepted(string $from, string $to): void
    {
        $machine = new PaymentStateMachine();

        $machine->assertCanTransition($from, $to, $this->actorRolesFor($from, $to), $this->reasonFor($to));

        self::assertTrue(true);
    }

    #[DataProvider('disallowedTransitions')]
    public function testDisallowedTransitionsAreRejected(string $from, string $to): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition($from, $to, ['learner', 'system', 'finance', 'admin'], 'reason');
    }

    public function testSameStatusTransitionIsRejectedAsConflict(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(ConflictException::class);
        $machine->assertCanTransition(PaymentStatus::PENDING, PaymentStatus::PENDING, ['system']);
    }

    public function testWrongActorForLearnerEdgeIsRejected(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(
            PaymentStatus::CREATED,
            PaymentStatus::CANCELLED,
            ['system'],
            'abandoned',
        );
    }

    public function testWrongActorForSystemEdgeIsRejected(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(
            PaymentStatus::CREATED,
            PaymentStatus::PENDING,
            ['learner'],
            'gateway_order_bound',
        );
    }

    public function testAdminCanPerformLearnerCancelEdge(): void
    {
        $machine = new PaymentStateMachine();
        $machine->assertCanTransition(
            PaymentStatus::CREATED,
            PaymentStatus::CANCELLED,
            ['admin'],
            'admin_cancel',
        );
        self::assertTrue(true);
    }

    public function testFinanceCanResolveReconciliation(): void
    {
        $machine = new PaymentStateMachine();
        $machine->assertCanTransition(
            PaymentStatus::RECONCILIATION_PENDING,
            PaymentStatus::SUCCESSFUL,
            ['finance'],
        );
        self::assertTrue(true);
    }

    public function testFailedWithoutReasonIsRejected(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(PaymentStatus::CREATED, PaymentStatus::FAILED, ['system']);
    }

    public function testCancelledWithoutReasonIsRejected(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(PaymentStatus::PENDING, PaymentStatus::CANCELLED, ['system']);
    }

    public function testExpiredWithoutReasonIsRejected(): void
    {
        $machine = new PaymentStateMachine();

        $this->expectException(DomainRuleException::class);
        $machine->assertCanTransition(PaymentStatus::PENDING, PaymentStatus::EXPIRED, ['system']);
    }

    public function testAllowedPairsAndDisallowedPairsPartitionTheFullMatrix(): void
    {
        $allowed = PaymentStateMachine::allowedPairs();
        $disallowed = PaymentStateMachine::disallowedPairs();

        $expectedTotal = count(PaymentStatus::ALL) * (count(PaymentStatus::ALL) - 1);
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
        return PaymentStateMachine::allowedPairs();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function disallowedTransitions(): array
    {
        return PaymentStateMachine::disallowedPairs();
    }

    /**
     * @return list<string>
     */
    private function actorRolesFor(string $from, string $to): array
    {
        $edge = $from . '>' . $to;
        if ($edge === PaymentStatus::CREATED . '>' . PaymentStatus::CANCELLED) {
            return ['learner'];
        }

        return ['system'];
    }

    private function reasonFor(string $to): ?string
    {
        if (in_array($to, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED], true)) {
            return 'test_reason';
        }

        return null;
    }
}
