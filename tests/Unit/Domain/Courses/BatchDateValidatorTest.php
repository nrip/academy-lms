<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Domain\Courses;

use Academy\Domain\Courses\BatchDateValidator;
use Academy\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class BatchDateValidatorTest extends TestCase
{
    private BatchDateValidator $validator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new BatchDateValidator();
        $this->now = new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    public function testValidDatesPassWithoutException(): void
    {
        $this->validator->validate(
            $this->now,
            $this->now->modify('+30 days'),
            $this->now->modify('-5 days'),
            $this->now->modify('+10 days'),
            5,
            30,
        );

        $this->addToAssertionCount(1);
    }

    public function testEndBeforeStartIsRejected(): void
    {
        try {
            $this->validator->validate(
                $this->now,
                $this->now->modify('-1 day'),
                $this->now->modify('-5 days'),
                $this->now->modify('+10 days'),
                5,
                30,
            );
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('ends_at', $exception->fields());
        }
    }

    public function testApplicationsCloseBeforeOpenIsRejected(): void
    {
        try {
            $this->validator->validate(
                $this->now,
                $this->now->modify('+30 days'),
                $this->now->modify('+10 days'),
                $this->now->modify('+5 days'),
                5,
                30,
            );
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('applications_close_at', $exception->fields());
        }
    }

    public function testNegativeMinCapacityIsRejected(): void
    {
        try {
            $this->validator->validate(
                $this->now,
                $this->now->modify('+30 days'),
                $this->now->modify('-5 days'),
                $this->now->modify('+10 days'),
                -1,
                30,
            );
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('min_capacity', $exception->fields());
        }
    }

    public function testMaxCapacityBelowMinCapacityIsRejected(): void
    {
        try {
            $this->validator->validate(
                $this->now,
                $this->now->modify('+30 days'),
                $this->now->modify('-5 days'),
                $this->now->modify('+10 days'),
                10,
                5,
            );
            self::fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('max_capacity', $exception->fields());
        }
    }

    public function testEqualStartEndAndOpenCloseAreAllowed(): void
    {
        $this->validator->validate($this->now, $this->now, $this->now, $this->now, 5, 5);
        $this->addToAssertionCount(1);
    }
}
