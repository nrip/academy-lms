<?php

declare(strict_types=1);

namespace Academy\Application\Identity;

use Academy\Application\RBAC\AuthorizationService;
use Academy\Domain\Identity\AccountStatus;
use Academy\Domain\Security\AuthContext;

/**
 * Central post-login destination selection (WP-07 review fix).
 * Permission-based only — never branches on role names.
 */
final class PostLoginDestinationResolver
{
    public const REVIEWER_QUEUE = '/reviewer/applications';
    public const FINANCE_RECONCILIATION = '/finance/reconciliation';
    public const FINANCE_PAYMENTS = '/finance/payments';
    public const NOTIFICATION_OPS = '/admin/notifications';
    public const LEARNER_DASHBOARD = '/dashboard';
    public const PROFILE = '/profile';
    public const COURSES = '/courses';

    /**
     * Exact internal paths eligible for return_to (no open redirect).
     * Value is the permission required to honor the destination, or null when always safe.
     *
     * @var array<string, string|null>
     */
    private const RETURN_TO_ALLOW_LIST = [
        self::REVIEWER_QUEUE => 'reviewer.queue.view',
        self::FINANCE_RECONCILIATION => 'finance.payment.reconcile',
        self::FINANCE_PAYMENTS => 'finance.payment.view',
        self::NOTIFICATION_OPS => 'notification.view',
        self::LEARNER_DASHBOARD => 'dashboard.view_own',
        self::PROFILE => 'profile.personal.view_own',
        self::COURSES => null,
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function resolve(AuthContext $auth, ?string $requestedReturnTo = null): string
    {
        if (!$auth->authenticated || $auth->userId === null) {
            return '/login';
        }

        $honored = $this->honorReturnTo($auth, $requestedReturnTo);
        if ($honored !== null) {
            return $honored;
        }

        // Non-active accounts never receive operational / learner privileged landings.
        if ($auth->accountStatus !== AccountStatus::ACTIVE) {
            return self::COURSES;
        }

        if ($this->authorization->check($auth, 'reviewer.queue.view')) {
            return self::REVIEWER_QUEUE;
        }
        if ($this->authorization->check($auth, 'finance.payment.reconcile')) {
            return self::FINANCE_RECONCILIATION;
        }
        if ($this->authorization->check($auth, 'finance.payment.view')) {
            return self::FINANCE_PAYMENTS;
        }
        if ($this->authorization->check($auth, 'notification.view')) {
            return self::NOTIFICATION_OPS;
        }
        if ($this->authorization->check($auth, 'dashboard.view_own')) {
            return self::LEARNER_DASHBOARD;
        }
        if ($this->authorization->check($auth, 'profile.personal.view_own')) {
            return self::PROFILE;
        }

        return self::COURSES;
    }

    /**
     * Validates and allow-lists return_to. Rejects external, protocol-relative,
     * malformed, and destinations the caller cannot access.
     */
    public function honorReturnTo(AuthContext $auth, ?string $requestedReturnTo): ?string
    {
        if ($requestedReturnTo === null) {
            return null;
        }

        $path = $this->normalizeInternalPath($requestedReturnTo);
        if ($path === null) {
            return null;
        }

        if (!array_key_exists($path, self::RETURN_TO_ALLOW_LIST)) {
            return null;
        }

        $permission = self::RETURN_TO_ALLOW_LIST[$path];
        if ($permission === null) {
            return $path;
        }

        if ($auth->accountStatus !== AccountStatus::ACTIVE) {
            return null;
        }

        return $this->authorization->check($auth, $permission) ? $path : null;
    }

    /**
     * @return list<string>
     */
    public static function allowListedPaths(): array
    {
        return array_keys(self::RETURN_TO_ALLOW_LIST);
    }

    private function normalizeInternalPath(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || strlen($trimmed) > 128) {
            return null;
        }

        // Reject absolute URLs, scheme-relative, backslashes, encoded tricks.
        if (
            str_contains($trimmed, '://')
            || str_starts_with($trimmed, '//')
            || str_contains($trimmed, '\\')
            || str_contains($trimmed, "\0")
            || str_contains($trimmed, '@')
        ) {
            return null;
        }

        if (!str_starts_with($trimmed, '/')) {
            return null;
        }

        // Path only — drop query/fragment if present after a path root.
        $path = parse_url($trimmed, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        // Collapse duplicate slashes; reject traversal.
        $path = (string) preg_replace('#/+#', '/', $path);
        if (str_contains($path, '..')) {
            return null;
        }

        // Trailing slash normalization (except root).
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
