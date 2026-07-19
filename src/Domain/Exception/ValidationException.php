<?php

declare(strict_types=1);

namespace Academy\Domain\Exception;

final class ValidationException extends DomainException
{
    /**
     * @param array<string, list<string>> $fields
     */
    public function __construct(
        string $message = 'Please correct the highlighted fields.',
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
