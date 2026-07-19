<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message);
    }
}
