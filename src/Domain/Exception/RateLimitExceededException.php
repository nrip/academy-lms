<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class RateLimitExceededException extends DomainException
{
    public function __construct(
        private readonly int $retryAfterSeconds,
        string $message = 'Too many requests.',
    ) {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds);
    }
}
