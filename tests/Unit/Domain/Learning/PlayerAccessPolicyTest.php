<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Learning;

use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Learning\Enrolment;
use Academy\Domain\Learning\EnrolmentLifecycleStatus;
use Academy\Domain\Learning\PlayerAccessPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PlayerAccessPolicyTest extends TestCase
{
    public function testOtherUserDenied(): void
    {
        $policy = new PlayerAccessPolicy();
        $this->expectException(AuthorizationException::class);
        $policy->assertCanViewOutline($this->enrolment(EnrolmentLifecycleStatus::ACTIVE, 9), 1);
    }

    public function testScheduledCannotAccessContent(): void
    {
        $policy = new PlayerAccessPolicy();
        $this->expectException(ConflictException::class);
        $policy->assertCanAccessContent($this->enrolment(EnrolmentLifecycleStatus::SCHEDULED, 1), 1);
    }

    public function testActiveCanAccessContent(): void
    {
        $policy = new PlayerAccessPolicy();
        $policy->assertCanAccessContent($this->enrolment(EnrolmentLifecycleStatus::ACTIVE, 1), 1);
        self::assertTrue(true);
    }

    private function enrolment(string $status, int $userId): Enrolment
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Enrolment(
            enrolmentId: 1,
            publicReference: 'ENR-1',
            applicationId: 1,
            userId: $userId,
            courseId: 1,
            courseVersionId: 1,
            batchId: 1,
            paymentId: 1,
            lifecycleStatus: $status,
            academicStatus: null,
            admittedAt: $now,
            activatedAt: $status === EnrolmentLifecycleStatus::ACTIVE ? $now : null,
            accessExpiresAt: null,
            rowVersion: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
