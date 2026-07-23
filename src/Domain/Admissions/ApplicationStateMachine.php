<?php

declare(strict_types=1);

namespace Academy\Domain\Admissions;

use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use DateTimeImmutable;

/**
 * Authoritative Application transitions per SRS §18.1 / AGENTS.md §6.1
 * (11 statuses; Cancelled omitted — see WP03_IMPLEMENTATION_NOTE.md).
 *
 * Repositories must not call this and then write status separately without
 * going through an application service transaction.
 */
final class ApplicationStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        ApplicationStatus::DRAFT => [
            ApplicationStatus::SUBMITTED,
            ApplicationStatus::WITHDRAWN,
        ],
        ApplicationStatus::SUBMITTED => [
            ApplicationStatus::DOCUMENTS_INCOMPLETE,
            ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::PAYMENT_PENDING,
        ],
        ApplicationStatus::DOCUMENTS_INCOMPLETE => [
            ApplicationStatus::UNDER_REVIEW,
        ],
        ApplicationStatus::UNDER_REVIEW => [
            ApplicationStatus::RESUBMISSION_REQUESTED,
            ApplicationStatus::PAYMENT_PENDING,
            ApplicationStatus::REJECTED,
        ],
        ApplicationStatus::RESUBMISSION_REQUESTED => [
            ApplicationStatus::UNDER_REVIEW,
            ApplicationStatus::EXPIRED,
        ],
        ApplicationStatus::PAYMENT_PENDING => [
            ApplicationStatus::AWAITING_VERIFICATION,
            ApplicationStatus::ADMITTED,
        ],
        ApplicationStatus::AWAITING_VERIFICATION => [
            ApplicationStatus::ADMITTED,
            ApplicationStatus::REJECTED,
        ],
        ApplicationStatus::ADMITTED => [],
        ApplicationStatus::REJECTED => [
            ApplicationStatus::WITHDRAWN,
        ],
        ApplicationStatus::WITHDRAWN => [],
        ApplicationStatus::EXPIRED => [],
    ];

    /**
     * @param list<string> $actorRoles Logical actor kinds: learner|system|reviewer|finance|admin
     */
    public function assertCanTransition(
        string $from,
        string $to,
        array $actorRoles,
        ?string $reason = null,
    ): void {
        ApplicationStatus::assertValid($from);
        ApplicationStatus::assertValid($to);

        if ($from === $to) {
            throw new ConflictException('Application is already in the requested status.');
        }

        $allowed = self::ALLOWED[$from];
        if (!in_array($to, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'Transition from %s to %s is not allowed.',
                $from,
                $to,
            ));
        }

        $this->assertActorAllowed($from, $to, $actorRoles);

        if (in_array($to, [ApplicationStatus::REJECTED, ApplicationStatus::WITHDRAWN, ApplicationStatus::EXPIRED], true)
            && ($reason === null || trim($reason) === '')
        ) {
            // Rejected/withdrawn/expired may require reasons in later WPs; draft withdraw
            // from learner is allowed without reason for Mode A abandon.
            if ($to === ApplicationStatus::REJECTED) {
                throw new DomainRuleException('A reason is required for rejection.');
            }
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
    ): ApplicationTransitionResult {
        $this->assertCanTransition($from, $to, $actorRoles, $reason);

        return new ApplicationTransitionResult(
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
        foreach (ApplicationStatus::ALL as $from) {
            foreach (ApplicationStatus::ALL as $to) {
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

        $reviewerEdges = [
            ApplicationStatus::UNDER_REVIEW . '>' . ApplicationStatus::RESUBMISSION_REQUESTED,
            ApplicationStatus::UNDER_REVIEW . '>' . ApplicationStatus::PAYMENT_PENDING,
            ApplicationStatus::UNDER_REVIEW . '>' . ApplicationStatus::REJECTED,
            ApplicationStatus::AWAITING_VERIFICATION . '>' . ApplicationStatus::ADMITTED,
            ApplicationStatus::AWAITING_VERIFICATION . '>' . ApplicationStatus::REJECTED,
        ];

        $systemOrReviewerEdges = [
            ApplicationStatus::RESUBMISSION_REQUESTED . '>' . ApplicationStatus::UNDER_REVIEW,
        ];

        $edge = $from . '>' . $to;

        if (in_array($edge, $learnerEdges, true)) {
            if (!in_array('learner', $roles, true) && !in_array('admin', $roles, true)) {
                throw new DomainRuleException('Learner actor required for this transition.');
            }

            return;
        }

        if (in_array($edge, $systemEdges, true)) {
            if (!in_array('system', $roles, true) && !in_array('admin', $roles, true)) {
                throw new DomainRuleException('System actor required for this transition.');
            }

            return;
        }

        if (in_array($edge, $reviewerEdges, true)) {
            if (!in_array('reviewer', $roles, true) && !in_array('admin', $roles, true)) {
                throw new DomainRuleException('Reviewer actor required for this transition.');
            }

            return;
        }

        if (in_array($edge, $systemOrReviewerEdges, true)) {
            $allowed = in_array('system', $roles, true)
                || in_array('reviewer', $roles, true)
                || in_array('admin', $roles, true);
            if (!$allowed) {
                throw new DomainRuleException('System or reviewer actor required for this transition.');
            }

            return;
        }

        throw new DomainRuleException('No actor policy configured for this transition.');
    }
}
