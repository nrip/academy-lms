<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Learning;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Learning\EnrolmentStateMachine;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EnrolmentStateMachineTest extends TestCase
{
    public function testAllAllowedPairsSucceed(): void
    {
        $machine = new EnrolmentStateMachine();
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach (EnrolmentStateMachine::allowedPairs() as [$from, $to]) {
            $actors = match (true) {
                $from === EnrolmentLifecycleStatus::ACTIVE && $to === EnrolmentLifecycleStatus::WITHDRAWN,
                $from === EnrolmentLifecycleStatus::SUSPENDED && $to === EnrolmentLifecycleStatus::WITHDRAWN
                    => ['learner'],
                $from === EnrolmentLifecycleStatus::WITHDRAWN && $to === EnrolmentLifecycleStatus::REFUNDED,
                $from === EnrolmentLifecycleStatus::ACCESS_EXPIRED && $to === EnrolmentLifecycleStatus::REFUNDED
                    => ['finance'],
                default => ['system'],
            };
            $reason = $to === EnrolmentLifecycleStatus::REFUNDED ? 'refund_approved' : null;
            $result = $machine->transition($from, $to, $actors, $at, $reason);
            self::assertSame($from, $result->fromStatus);
            self::assertSame($to, $result->toStatus);
        }
    }

    public function testSampleDisallowedPairRejected(): void
    {
        $this->expectException(DomainRuleException::class);
        (new EnrolmentStateMachine())->assertCanTransition(
            EnrolmentLifecycleStatus::ACTIVE,
            EnrolmentLifecycleStatus::SCHEDULED,
            ['admin'],
        );
    }
}
