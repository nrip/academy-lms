<?php

declare(strict_types=1);

namespace Academy\Http\Security;

use InvalidArgumentException;

/**
 * Purpose-specific confirmation cookies for scanner-safe verify/reset flows.
 * Separate cookies: email confirm vs password-reset confirm — never shared.
 */
final class ConfirmationCookieSettings
{
    public const EMAIL_CONFIRM_HOST = '__Host-acad_email_confirm';
    public const RESET_CONFIRM_HOST = '__Host-acad_reset_confirm';
    public const EMAIL_CONFIRM_PLAIN = 'acad_email_confirm';
    public const RESET_CONFIRM_PLAIN = 'acad_reset_confirm';

    public function __construct(
        public readonly string $emailConfirmCookieName,
        public readonly string $resetConfirmCookieName,
        public readonly bool $secure,
        public readonly string $sameSite = 'Lax',
        public readonly string $path = '/',
        public readonly string $domain = '',
    ) {
        $this->validate();
    }

    public static function fromEnvFlags(bool $useHostPrefix, bool $secure): self
    {
        return new self(
            $useHostPrefix ? self::EMAIL_CONFIRM_HOST : self::EMAIL_CONFIRM_PLAIN,
            $useHostPrefix ? self::RESET_CONFIRM_HOST : self::RESET_CONFIRM_PLAIN,
            $secure,
        );
    }

    public function cookieNameForPurpose(string $purpose): string
    {
        return match ($purpose) {
            'email_verify' => $this->emailConfirmCookieName,
            'password_reset' => $this->resetConfirmCookieName,
            default => throw new InvalidArgumentException('Unknown confirmation purpose.'),
        };
    }

    public function buildSetCookie(string $purpose, string $rawConfirmationSecret): string
    {
        $name = $this->cookieNameForPurpose($purpose);

        return $this->buildCookieHeader($name, $rawConfirmationSecret, false);
    }

    public function buildClearCookie(string $purpose): string
    {
        $name = $this->cookieNameForPurpose($purpose);

        return $this->buildCookieHeader($name, '', true);
    }

    private function buildCookieHeader(string $name, string $value, bool $clear): string
    {
        $parts = [
            $name . '=' . ($clear ? '' : rawurlencode($value)),
            'Path=' . $this->path,
            'SameSite=' . $this->sameSite,
            'HttpOnly',
        ];
        if ($clear) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function validate(): void
    {
        $this->validateCookieName($this->emailConfirmCookieName);
        $this->validateCookieName($this->resetConfirmCookieName);
        if ($this->emailConfirmCookieName === $this->resetConfirmCookieName) {
            throw new InvalidArgumentException('Email and reset confirmation cookies must be distinct.');
        }
        if ($this->path !== '/') {
            throw new InvalidArgumentException('Confirmation cookies require Path=/');
        }
        if ($this->domain !== '') {
            throw new InvalidArgumentException('Confirmation cookies must not include a Domain attribute.');
        }
    }

    private function validateCookieName(string $name): void
    {
        if (!str_starts_with($name, '__Host-')) {
            return;
        }
        if (!$this->secure) {
            throw new InvalidArgumentException('__Host- cookies require the Secure attribute.');
        }
    }
}
