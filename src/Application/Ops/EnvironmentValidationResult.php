<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

/**
 * Actionable validation outcome. Messages must never include secret values.
 */
final class EnvironmentValidationResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        private readonly bool $ok,
        private readonly array $errors,
        private readonly array $warnings = [],
    ) {
    }

    /**
     * @param list<string> $warnings
     */
    public static function success(array $warnings = []): self
    {
        return new self(true, [], array_values($warnings));
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public static function failure(array $errors, array $warnings = []): self
    {
        return new self(false, array_values($errors), array_values($warnings));
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
