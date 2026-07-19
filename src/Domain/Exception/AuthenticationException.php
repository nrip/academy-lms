<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class AuthenticationException extends DomainException
{
    public function __construct(string $message = 'Authentication is required.')
    {
        parent::__construct($message);
    }
}
