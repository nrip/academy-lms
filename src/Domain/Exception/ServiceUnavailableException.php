<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class ServiceUnavailableException extends DomainException
{
    public function __construct(string $message = 'Service temporarily unavailable.')
    {
        parent::__construct($message);
    }
}
