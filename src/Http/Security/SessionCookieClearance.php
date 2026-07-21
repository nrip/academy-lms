<?php

declare(strict_types=1);

namespace Academy\Http\Security;

/**
 * Mutable request-scoped flag so AuthenticationMiddleware can request cookie
 * clearance even when a downstream exception prevents the normal response unwind
 * (ExceptionHandler builds a fresh error response).
 *
 * @extends \ArrayObject<string, bool>
 */
final class SessionCookieClearance extends \ArrayObject
{
    public const ATTR = 'security.session_cookie_clearance';

    public function __construct()
    {
        parent::__construct(['clear' => false]);
    }

    public function requestClear(): void
    {
        $this['clear'] = true;
    }

    public function shouldClear(): bool
    {
        return $this['clear'] === true;
    }
}
