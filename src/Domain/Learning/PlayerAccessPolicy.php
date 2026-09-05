<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;

/**
 * Gates learner player access by ownership and enrolment lifecycle (no SM changes).
 */
final class PlayerAccessPolicy
{
    public function assertOwned(Enrolment $enrolment, int $userId): void
    {
        if (!$enrolment->belongsToUser($userId)) {
            throw new AuthorizationException('Enrolment is not owned by the authenticated user.');
        }
    }

    public function assertCanViewOutline(Enrolment $enrolment, int $userId): void
    {
        $this->assertOwned($enrolment, $userId);

        $allowed = [
            EnrolmentLifecycleStatus::ACTIVE,
            EnrolmentLifecycleStatus::SCHEDULED,
        ];
        if (!in_array($enrolment->lifecycleStatus, $allowed, true)) {
            throw new DomainRuleException('This enrolment cannot access the course player.');
        }
    }

    public function assertCanAccessContent(Enrolment $enrolment, int $userId): void
    {
        $this->assertOwned($enrolment, $userId);

        if ($enrolment->lifecycleStatus === EnrolmentLifecycleStatus::SCHEDULED) {
            throw new ConflictException('Your batch has not started yet. Content unlocks when the enrolment is Active.');
        }

        if ($enrolment->lifecycleStatus !== EnrolmentLifecycleStatus::ACTIVE) {
            throw new DomainRuleException('Only an Active enrolment can open learning content.');
        }
    }
}
