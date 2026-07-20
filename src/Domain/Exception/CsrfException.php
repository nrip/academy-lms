<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class CsrfException extends DomainException
{
    public function __construct(string $message = 'CSRF validation failed.')
    {
        parent::__construct($message);
    }
}
