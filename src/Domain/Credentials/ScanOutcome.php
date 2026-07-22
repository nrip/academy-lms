<?php

declare(strict_types=1);

namespace Academy\Domain\Credentials;

final class ScanOutcome
{
    public function __construct(
        public readonly string $scanStatus,
        public readonly ?string $reasonCode = null,
    ) {
    }

    public function isClean(): bool
    {
        return $this->scanStatus === DocumentScanStatus::CLEAN;
    }
}
