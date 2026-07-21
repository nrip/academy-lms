<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class AuthVersionCeilingException extends DomainException
{
    public function __construct(string $message = 'User auth_version has reached the application ceiling.')
    {
        parent::__construct($message);
    }
}
