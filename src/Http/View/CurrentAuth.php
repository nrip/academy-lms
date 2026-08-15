<?php

declare(strict_types=1);

namespace Academy\Http\View;

use Academy\Domain\Security\AuthContext;

/**
 * Request-scoped auth holder for view composition (navigation).
 * Set by AuthenticationMiddleware; read by PhpRenderer.
 */
final class CurrentAuth
{
    private ?AuthContext $auth = null;

    public function set(?AuthContext $auth): void
    {
        $this->auth = $auth;
    }

    public function get(): ?AuthContext
    {
        return $this->auth;
    }

    public function clear(): void
    {
        $this->auth = null;
    }
}
