<?php

declare(strict_types=1);

namespace Academy\Domain\Learning;

interface EnrolmentStatusHistoryRepository
{
    public function append(EnrolmentStatusHistoryWrite $write): void;
}
