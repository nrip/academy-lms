<?php

declare(strict_types=1);

namespace Academy\Application\Branding;

/**
 * Single-deployment academy branding (not multi-tenant).
 * Values come from environment / config — no admin UI in Phase 1.
 */
final class AcademyBranding
{
    public const DEFAULT_NAME = 'Academy LMS';
    public const DEFAULT_LOGO_URL = '/assets/brand/logo.svg';
    public const DEFAULT_PRIMARY_COLOR = '#0F6E62';

    public function __construct(
        public readonly string $name,
        public readonly string $logoUrl,
        public readonly string $primaryColor,
        public readonly string $supportEmail,
        public readonly string $certificateIssuerName,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_NAME,
            self::DEFAULT_LOGO_URL,
            self::DEFAULT_PRIMARY_COLOR,
            '',
            self::DEFAULT_NAME,
        );
    }

    /**
     * @param array{
     *   name?: mixed,
     *   logo_url?: mixed,
     *   primary_color?: mixed,
     *   support_email?: mixed,
     *   certificate_issuer_name?: mixed
     * } $config
     */
    public static function fromConfig(array $config): self
    {
        $name = self::nonEmptyString($config['name'] ?? null, self::DEFAULT_NAME);
        $logoUrl = self::sanitizeLogoUrl(
            self::nonEmptyString($config['logo_url'] ?? null, self::DEFAULT_LOGO_URL),
        );
        $primaryColor = self::sanitizePrimaryColor(
            self::nonEmptyString($config['primary_color'] ?? null, self::DEFAULT_PRIMARY_COLOR),
        );
        $supportEmail = self::sanitizeSupportEmail(
            is_string($config['support_email'] ?? null) ? $config['support_email'] : '',
        );
        $issuer = self::nonEmptyString($config['certificate_issuer_name'] ?? null, $name);

        return new self($name, $logoUrl, $primaryColor, $supportEmail, $issuer);
    }

    /** Comma-separated RGB for Bootstrap --bs-primary-rgb. */
    public function primaryColorRgb(): string
    {
        $hex = ltrim($this->primaryColor, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('%d, %d, %d', $r, $g, $b);
    }

    private static function nonEmptyString(mixed $value, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : $default;
    }

    private static function sanitizePrimaryColor(string $color): string
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1) {
            return strtoupper($color);
        }

        return self::DEFAULT_PRIMARY_COLOR;
    }

    private static function sanitizeLogoUrl(string $url): string
    {
        if (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        // Same-origin asset paths only (reject protocol-relative //host).
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')
            && preg_match('#^/[A-Za-z0-9/._\\-]+$#', $url) === 1
        ) {
            return $url;
        }

        return self::DEFAULT_LOGO_URL;
    }

    private static function sanitizeSupportEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }
}
