<?php

declare(strict_types=1);

namespace Academy\Domain\Payments;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use DateTimeImmutable;

/**
 * Authoritative Payment transitions per AGENTS.md §6.3 / SRS REQ-PAY-7.
 * Controllers/repos must not set status outside this machine.
 */
final class PaymentStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        PaymentStatus::CREATED => [
            PaymentStatus::PENDING,
            PaymentStatus::FAILED,
            PaymentStatus::CANCELLED,
        ],
        PaymentStatus::PENDING => [
            PaymentStatus::SUCCESSFUL,
            PaymentStatus::FAILED,
            PaymentStatus::CANCELLED,
            PaymentStatus::EXPIRED,
            PaymentStatus::RECONCILIATION_PENDING,
        ],
        PaymentStatus::SUCCESSFUL => [
            PaymentStatus::REFUNDED,
            PaymentStatus::PARTIALLY_REFUNDED,
            PaymentStatus::DISPUTED,
            PaymentStatus::RECONCILIATION_PENDING,
        ],
        PaymentStatus::FAILED => [],
        PaymentStatus::CANCELLED => [],
        PaymentStatus::EXPIRED => [],
        PaymentStatus::RECONCILIATION_PENDING => [
            PaymentStatus::SUCCESSFUL,
            PaymentStatus::FAILED,
            PaymentStatus::CANCELLED,
            PaymentStatus::REFUNDED,
        ],
        PaymentStatus::REFUNDED => [],
        PaymentStatus::PARTIALLY_REFUNDED => [
            PaymentStatus::REFUNDED,
            PaymentStatus::DISPUTED,
        ],
        PaymentStatus::DISPUTED => [
            PaymentStatus::SUCCESSFUL,
            PaymentStatus::REFUNDED,
        ],
    ];

    /**
     * @param list<string> $actorRoles learner|system|finance|admin
     */
    public function assertCanTransition(
        string $from,
        string $to,
        array $actorRoles,
        ?string $reason = null,
    ): void {
        PaymentStatus::assertValid($from);
        PaymentStatus::assertValid($to);

        if ($from === $to) {
            throw new ConflictException('Payment is already in the requested status.');
        }

        $allowed = self::ALLOWED[$from];
        if (!in_array($to, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'Payment transition from %s to %s is not allowed.',
                $from,
                $to,
            ));
        }

        $this->assertActorAllowed($from, $to, $actorRoles);

        if (in_array($to, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED], true)
            && ($reason === null || trim($reason) === '')
        ) {
            // Failed/cancelled/expired should carry a failure category for auditability.
            throw new DomainRuleException('A reason is required for this payment transition.');
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
    ): PaymentTransitionResult {
        $this->assertCanTransition($from, $to, $actorRoles, $reason);

        return new PaymentTransitionResult(
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
        foreach (PaymentStatus::ALL as $from) {
            foreach (PaymentStatus::ALL as $to) {
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
            PaymentStatus::CREATED . '>' . PaymentStatus::CANCELLED,
        ];

        $systemEdges = [
            PaymentStatus::CREATED . '>' . PaymentStatus::PENDING,
            PaymentStatus::CREATED . '>' . PaymentStatus::FAILED,
            PaymentStatus::PENDING . '>' . PaymentStatus::SUCCESSFUL,
            PaymentStatus::PENDING . '>' . PaymentStatus::FAILED,
            PaymentStatus::PENDING . '>' . PaymentStatus::CANCELLED,
            PaymentStatus::PENDING . '>' . PaymentStatus::EXPIRED,
            PaymentStatus::PENDING . '>' . PaymentStatus::RECONCILIATION_PENDING,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::RECONCILIATION_PENDING,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::DISPUTED,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::PARTIALLY_REFUNDED,
            PaymentStatus::PARTIALLY_REFUNDED . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::PARTIALLY_REFUNDED . '>' . PaymentStatus::DISPUTED,
            PaymentStatus::DISPUTED . '>' . PaymentStatus::SUCCESSFUL,
            PaymentStatus::DISPUTED . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::SUCCESSFUL,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::FAILED,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::CANCELLED,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::REFUNDED,
        ];

        $financeEdges = [
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::SUCCESSFUL,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::FAILED,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::CANCELLED,
            PaymentStatus::RECONCILIATION_PENDING . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::PARTIALLY_REFUNDED,
            PaymentStatus::SUCCESSFUL . '>' . PaymentStatus::DISPUTED,
            PaymentStatus::PARTIALLY_REFUNDED . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::DISPUTED . '>' . PaymentStatus::REFUNDED,
            PaymentStatus::DISPUTED . '>' . PaymentStatus::SUCCESSFUL,
        ];

        if (in_array($edge, $learnerEdges, true)) {
            if (!in_array('learner', $roles, true) && !in_array('admin', $roles, true)) {
                throw new DomainRuleException('Learner actor required for this payment transition.');
            }

            return;
        }

        if (in_array($edge, $systemEdges, true) || in_array($edge, $financeEdges, true)) {
            $ok = in_array('system', $roles, true)
                || in_array('finance', $roles, true)
                || in_array('admin', $roles, true);
            if (!$ok) {
                throw new DomainRuleException('System or finance actor required for this payment transition.');
            }

            return;
        }

        throw new DomainRuleException('No actor policy configured for this payment transition.');
    }
}
