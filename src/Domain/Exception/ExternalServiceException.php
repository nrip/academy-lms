<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class ExternalServiceException extends DomainException
{
    public function __construct(string $message = 'An external service error occurred.')
    {
        parent::__construct($message);
    }
}
