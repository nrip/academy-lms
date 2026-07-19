<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class ConflictException extends DomainException
{
    public function __construct(string $message = 'The request conflicts with the current state.')
    {
        parent::__construct($message);
    }
}
