<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class AuthorizationException extends DomainException
{
    public function __construct(string $message = 'You are not authorised to perform this action.')
    {
        parent::__construct($message);
    }
}
