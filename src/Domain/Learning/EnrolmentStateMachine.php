<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use DateTimeImmutable;

/**
 * Authoritative Enrolment lifecycle transitions per SRS §18.2 / AGENTS.md §6.2.
 */
final class EnrolmentStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        EnrolmentLifecycleStatus::SCHEDULED => [
            EnrolmentLifecycleStatus::ACTIVE,
            EnrolmentLifecycleStatus::CANCELLED,
        ],
        EnrolmentLifecycleStatus::ACTIVE => [
            EnrolmentLifecycleStatus::SUSPENDED,
            EnrolmentLifecycleStatus::WITHDRAWN,
            EnrolmentLifecycleStatus::ACCESS_EXPIRED,
        ],
        EnrolmentLifecycleStatus::SUSPENDED => [
            EnrolmentLifecycleStatus::ACTIVE,
            EnrolmentLifecycleStatus::WITHDRAWN,
        ],
        EnrolmentLifecycleStatus::WITHDRAWN => [
            EnrolmentLifecycleStatus::REFUNDED,
        ],
        EnrolmentLifecycleStatus::ACCESS_EXPIRED => [
            EnrolmentLifecycleStatus::REFUNDED,
        ],
        EnrolmentLifecycleStatus::CANCELLED => [],
        EnrolmentLifecycleStatus::REFUNDED => [],
    ];

    /**
     * @param list<string> $actorRoles system|admin|learner|finance
     */
    public function assertCanTransition(
        string $from,
        string $to,
        array $actorRoles,
        ?string $reason = null,
    ): void {
        EnrolmentLifecycleStatus::assertValid($from);
        EnrolmentLifecycleStatus::assertValid($to);

        if ($from === $to) {
            throw new ConflictException('Enrolment is already in the requested lifecycle status.');
        }

        $allowed = self::ALLOWED[$from];
        if (!in_array($to, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'Enrolment transition from %s to %s is not allowed.',
                $from,
                $to,
            ));
        }

        $this->assertActorAllowed($from, $to, $actorRoles);

        if ($to === EnrolmentLifecycleStatus::REFUNDED
            && ($reason === null || trim($reason) === '')
        ) {
            throw new DomainRuleException('A reason is required for refunded enrolment transition.');
        }
    }

    /**
     * @param list<string> $actorRoles
     */
    public function transition(
        string $from,
        string $to,
        array $actorRoles,
        DateTimeImmutable $at,
        ?string $reason = null,
    ): EnrolmentTransitionResult {
        $this->assertCanTransition($from, $to, $actorRoles, $reason);

        return new EnrolmentTransitionResult(
            fromStatus: $from,
            toStatus: $to,
            transitionedAt: $at,
            reason: $reason,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function allowedMatrix(): array
    {
        return self::ALLOWED;
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
        foreach (EnrolmentLifecycleStatus::ALL as $from) {
            foreach (EnrolmentLifecycleStatus::ALL as $to) {
                if ($from === $to) {
                    continue;
                }
                if (!in_array($to, self::ALLOWED[$from], true)) {
                    $disallowed[] = [$from, $to];
                }
            }
        }

        return $disallowed;
    }

    /**
     * @param list<string> $actorRoles
     */
    private function assertActorAllowed(string $from, string $to, array $actorRoles): void
    {
        $roles = array_values(array_unique($actorRoles));
        $edge = $from . '>' . $to;

        $learnerEdges = [
            EnrolmentLifecycleStatus::ACTIVE . '>' . EnrolmentLifecycleStatus::WITHDRAWN,
            EnrolmentLifecycleStatus::SUSPENDED . '>' . EnrolmentLifecycleStatus::WITHDRAWN,
        ];

        $systemEdges = [
            EnrolmentLifecycleStatus::SCHEDULED . '>' . EnrolmentLifecycleStatus::ACTIVE,
            EnrolmentLifecycleStatus::SCHEDULED . '>' . EnrolmentLifecycleStatus::CANCELLED,
            EnrolmentLifecycleStatus::ACTIVE . '>' . EnrolmentLifecycleStatus::SUSPENDED,
            EnrolmentLifecycleStatus::ACTIVE . '>' . EnrolmentLifecycleStatus::ACCESS_EXPIRED,
            EnrolmentLifecycleStatus::SUSPENDED . '>' . EnrolmentLifecycleStatus::ACTIVE,
            EnrolmentLifecycleStatus::WITHDRAWN . '>' . EnrolmentLifecycleStatus::REFUNDED,
            EnrolmentLifecycleStatus::ACCESS_EXPIRED . '>' . EnrolmentLifecycleStatus::REFUNDED,
        ];

        $adminEdges = array_merge($systemEdges, $learnerEdges, [
            EnrolmentLifecycleStatus::ACTIVE . '>' . EnrolmentLifecycleStatus::WITHDRAWN,
            EnrolmentLifecycleStatus::SUSPENDED . '>' . EnrolmentLifecycleStatus::WITHDRAWN,
        ]);

        $financeEdges = [
            EnrolmentLifecycleStatus::WITHDRAWN . '>' . EnrolmentLifecycleStatus::REFUNDED,
            EnrolmentLifecycleStatus::ACCESS_EXPIRED . '>' . EnrolmentLifecycleStatus::REFUNDED,
        ];

        if (in_array($edge, $learnerEdges, true)) {
            if (!in_array('learner', $roles, true) && !in_array('admin', $roles, true)) {
                throw new DomainRuleException('Learner or admin actor required for this enrolment transition.');
            }

            return;
        }

        if (in_array($edge, $financeEdges, true)) {
            $ok = in_array('finance', $roles, true)
                || in_array('system', $roles, true)
                || in_array('admin', $roles, true);
            if (!$ok) {
                throw new DomainRuleException('Finance, system or admin actor required for refunded enrolment.');
            }

            return;
        }

        if (in_array($edge, $systemEdges, true) || in_array($edge, $adminEdges, true)) {
            $ok = in_array('system', $roles, true) || in_array('admin', $roles, true);
            if (!$ok) {
                throw new DomainRuleException('System or admin actor required for this enrolment transition.');
            }

            return;
        }

        throw new DomainRuleException('No actor policy configured for this enrolment transition.');
    }
}
