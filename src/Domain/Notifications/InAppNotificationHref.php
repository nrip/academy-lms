<?php

declare(strict_types=1);

namespace Academy\Domain\Notifications;

/**
 * Turns a rendered template link into an internal path for the learner inbox.
 * Query strings are dropped so a token cannot be stored as a link.
 */
final class InAppNotificationHref
{
    /**
     * @param array<string, mixed> $variables
     */
    public static function fromVariables(string $eventType, array $variables): string
    {
        if ($eventType === TransactionalNotificationEventTypes::CERTIFICATE_ISSUED) {
            $certificate = self::internalPath($variables['certificate_link'] ?? null);
            if ($certificate !== null) {
                return $certificate;
            }
        }
        if (
            $eventType === TransactionalNotificationEventTypes::APPLICATION_ADMITTED
            || $eventType === TransactionalNotificationEventTypes::ENROLMENT_CREATED
        ) {
            $learning = self::internalPath($variables['learning_link'] ?? null);
            if ($learning !== null) {
                return $learning;
            }
        }

        return self::internalPath($variables['dashboard_link'] ?? null) ?? '/dashboard';
    }

    public static function internalPath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '//') || str_contains($value, "\n") || str_contains($value, "\r")) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || !self::isAllowed($path)) {
            return null;
        }

        return $path;
    }

    private static function isAllowed(string $path): bool
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }
        if (in_array($path, ['/dashboard', '/courses', '/profile'], true)) {
            return true;
        }
        foreach (['/applications/', '/learning/', '/certificates/', '/profile/', '/courses/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
