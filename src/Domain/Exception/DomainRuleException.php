<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class DomainRuleException extends DomainException
{
    public function __construct(string $message = 'A domain rule was violated.')
    {
        parent::__construct($message);
    }
}
