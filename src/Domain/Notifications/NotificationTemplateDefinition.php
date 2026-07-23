<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;

/**
 * Code-owned transactional email template (WP-07). Versioned; variable allow-list enforced.
 */
final class NotificationTemplateDefinition
{
    /**
     * @param list<string> $allowedVariables
     */
    public function __construct(
        public readonly string $key,
        public readonly string $channel,
        public readonly int $version,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $allowedVariables,
        public readonly bool $active = true,
    ) {
        if ($key === '' || $channel === '' || $version < 1) {
            throw new ValidationException('Invalid notification template definition.');
        }
        foreach ($allowedVariables as $variable) {
            if (!is_string($variable) || $variable === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $variable)) {
                throw new ValidationException('Invalid template variable name.');
            }
        }
    }

    /**
     * @param array<string, string> $variables
     */
    public function assertVariables(array $variables): void
    {
        $allowed = array_fill_keys($this->allowedVariables, true);
        foreach (array_keys($variables) as $key) {
            if (!isset($allowed[$key])) {
                throw new DomainRuleException('Template variable "' . $key . '" is not allow-listed.');
            }
        }
    }
}
