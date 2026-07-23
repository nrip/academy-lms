<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Notifications\NotificationTemplateDefinition;

/**
 * Renders allow-listed {{variables}} only. Unknown placeholders fail closed.
 */
final class NotificationTemplateRenderer
{
    /**
     * @param array<string, string> $variables
     * @return array{subject: string, body: string}
     */
    public function render(NotificationTemplateDefinition $template, array $variables): array
    {
        $template->assertVariables($variables);

        $subject = $this->interpolate($template->subject, $variables, $template->allowedVariables);
        $body = $this->interpolate($template->body, $variables, $template->allowedVariables);

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @param array<string, string> $variables
     * @param list<string> $allowed
     */
    private function interpolate(string $text, array $variables, array $allowed): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/',
            static function (array $matches) use ($variables, $allowed): string {
                $key = $matches[1];
                if (!in_array($key, $allowed, true)) {
                    throw new DomainRuleException('Template contains non-allow-listed placeholder.');
                }
                if (!array_key_exists($key, $variables)) {
                    throw new DomainRuleException('Missing required template variable: ' . $key);
                }

                // Escape mustache-like injection of nested placeholders.
                return str_replace(['{', '}'], ['(', ')'], $variables[$key]);
            },
            $text,
        );
    }
}
