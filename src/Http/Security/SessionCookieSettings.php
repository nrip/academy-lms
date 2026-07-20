<?php

declare(strict_types=1);

namespace Academy\Http\Security;

use InvalidArgumentException;

/**
 * Session and CSRF cookie emission rules with __Host- prefix validation.
 */
final class SessionCookieSettings
{
    public function __construct(
        public readonly string $sessionCookieName,
        public readonly string $csrfCookieName,
        public readonly bool $secure,
        public readonly string $sameSite = 'Lax',
        public readonly string $path = '/',
        public readonly string $domain = '',
    ) {
        $this->validate();
    }

    /**
     * @param array{
     *   cookie_secure: bool,
     *   cookies: array{session_name: string, csrf_name: string}
     * } $session
     */
    public static function fromSessionConfig(array $session): self
    {
        return new self(
            $session['cookies']['session_name'],
            $session['cookies']['csrf_name'],
            $session['cookie_secure'],
        );
    }

    public function buildSessionSetCookie(string $rawToken): string
    {
        return $this->buildSetCookie($this->sessionCookieName, $rawToken, true);
    }

    public function buildCsrfSetCookie(string $rawCsrf): string
    {
        return $this->buildSetCookie($this->csrfCookieName, $rawCsrf, false);
    }

    public function buildSetCookie(string $name, string $value, bool $httpOnly): string
    {
        $this->validateCookieName($name);

        $parts = [
            $name . '=' . rawurlencode($value),
            'Path=' . $this->path,
            'SameSite=' . $this->sameSite,
        ];
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->domain !== '' && !str_starts_with($name, '__Host-')) {
            $parts[] = 'Domain=' . $this->domain;
        }

        return implode('; ', $parts);
    }

    private function validate(): void
    {
        $this->validateCookieName($this->sessionCookieName);
        $this->validateCookieName($this->csrfCookieName);
    }

    private function validateCookieName(string $name): void
    {
        if (!str_starts_with($name, '__Host-')) {
            return;
        }

        if (!$this->secure) {
            throw new InvalidArgumentException('__Host- cookies require the Secure attribute.');
        }
        if ($this->domain !== '') {
            throw new InvalidArgumentException('__Host- cookies must not include a Domain attribute.');
        }
        if ($this->path !== '/') {
            throw new InvalidArgumentException('__Host- cookies require Path=/');
        }
    }
}
