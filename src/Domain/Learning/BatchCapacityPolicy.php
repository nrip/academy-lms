<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

use Academy\Domain\Courses\Batch;
use Academy\Domain\Exception\ConflictException;

final class BatchCapacityPolicy
{
    public function assertSeatAvailable(Batch $batch, int $occupiedSeats): void
    {
        if ($occupiedSeats >= $batch->maxCapacity) {
            throw new ConflictException('Batch capacity is exhausted.');
        }
    }

    public function hasAvailableSeat(Batch $batch, int $occupiedSeats): bool
    {
        return $occupiedSeats < $batch->maxCapacity;
    }
}
