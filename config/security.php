<?php

declare(strict_types=1);

use Academy\Http\Security\SessionCookieSettings;

/**
 * WP-01A security configuration (authoritative source).
 *
 * @param callable(string, bool): bool $bool
 * @param callable(string, string): string $string
 * @param callable(string, int): int $int
 *
 * @return array{
 *   trusted_proxies: list<string>,
 *   force_https: bool,
 *   rate_limit_pepper: string,
 *   session: array{
 *     cookie_secure: bool,
 *     cookies: array{session_name: string, csrf_name: string},
 *     activity_write_throttle_seconds: int,
 *     required_path_prefixes: list<string>,
 *     timeouts: array{
 *       default: array{idle_seconds: int, absolute_seconds: int},
 *       privileged: array{idle_seconds: int, absolute_seconds: int}
 *     }
 *   },
 *   rate_limit: array{
 *     policies: array<string, array{limit: int, window_seconds: int, failure: string}>,
 *     path_policies: array<string, string>
 *   },
 *   outbox: array{
 *     lease_seconds: int,
 *     max_attempts: int,
 *     backoff_base_seconds: int,
 *     backoff_cap_seconds: int,
 *     transport: string
 *   }
 * }
 */
return static function (string $env, callable $bool, callable $string, callable $int): array {
    $forceHttps = $bool('FORCE_HTTPS', $env === 'production');
    $cookieSecure = $bool('SESSION_COOKIE_SECURE', in_array($env, ['production', 'staging'], true) || $forceHttps);
    $useHostPrefix = in_array($env, ['production', 'staging'], true) && $cookieSecure;

    $sessionCookieName = $useHostPrefix ? '__Host-acad_session' : 'acad_session';
    $csrfCookieName = $useHostPrefix ? '__Host-acad_csrf' : 'acad_csrf';

    // Fail fast on invalid __Host- combinations at bootstrap.
    new SessionCookieSettings($sessionCookieName, $csrfCookieName, $cookieSecure);

    $rateLimitPepper = $string('RATE_LIMIT_PEPPER', '');
    if ($rateLimitPepper === '' && in_array($env, ['local', 'testing', 'ci'], true)) {
        $rateLimitPepper = 'local-ci-rate-limit-pepper-not-for-production';
    }

    $policies = [
        'auth.login_failed' => ['limit' => 5, 'window_seconds' => 15 * 60, 'failure' => 'fail_closed'],
        'auth.otp_send.15m' => ['limit' => 3, 'window_seconds' => 15 * 60, 'failure' => 'fail_closed'],
        'auth.otp_send.24h' => ['limit' => 10, 'window_seconds' => 24 * 60 * 60, 'failure' => 'fail_closed'],
        'auth.otp_verify' => ['limit' => 10, 'window_seconds' => 15 * 60, 'failure' => 'fail_closed'],
        'auth.password_reset.account' => ['limit' => 3, 'window_seconds' => 60 * 60, 'failure' => 'fail_closed'],
        'auth.password_reset.ip' => ['limit' => 10, 'window_seconds' => 60 * 60, 'failure' => 'fail_closed'],
        'auth.registration' => ['limit' => 10, 'window_seconds' => 60 * 60, 'failure' => 'fail_closed'],
        'auth.verify_link.ip' => ['limit' => 20, 'window_seconds' => 15 * 60, 'failure' => 'fail_closed'],
        'auth.verify_link.token' => ['limit' => 10, 'window_seconds' => 15 * 60, 'failure' => 'fail_closed'],
        'public.certificate_verify' => ['limit' => 60, 'window_seconds' => 60, 'failure' => 'fail_open'],
        'authenticated.default' => ['limit' => 120, 'window_seconds' => 60, 'failure' => 'fail_closed'],
        'authenticated.read' => ['limit' => 120, 'window_seconds' => 60, 'failure' => 'fail_open'],
        'documents.upload_init' => ['limit' => 20, 'window_seconds' => 60 * 60, 'failure' => 'fail_closed'],
        'payments.checkout' => ['limit' => 5, 'window_seconds' => 30 * 60, 'failure' => 'fail_closed'],
        'admin.mutation' => ['limit' => 60, 'window_seconds' => 60, 'failure' => 'fail_closed'],
        'public.catalogue' => ['limit' => 300, 'window_seconds' => 60, 'failure' => 'fail_open'],
    ];

    $pathPolicies = [];
    if ($env === 'testing') {
        $policies['test.tight'] = ['limit' => 3, 'window_seconds' => 60, 'failure' => 'fail_closed'];
        $pathPolicies['POST /__wp01a/limited'] = 'test.tight';
    }

    $requiredPathPrefixes = $env === 'testing' ? ['/__wp01a/protected'] : [];

    $softSecretsAllowed = in_array($env, ['local', 'testing', 'ci'], true);

    $tokenPepper = $string('TOKEN_PEPPER', '');
    if ($tokenPepper === '' && $softSecretsAllowed) {
        $tokenPepper = 'local-ci-token-pepper-not-for-production';
    }
    if ($tokenPepper === '') {
        throw new InvalidArgumentException('TOKEN_PEPPER must be configured.');
    }

    $otpPepper = $string('OTP_PEPPER', '');
    if ($otpPepper === '' && $softSecretsAllowed) {
        $otpPepper = 'local-ci-otp-pepper-not-for-production';
    }
    if ($otpPepper === '') {
        throw new InvalidArgumentException('OTP_PEPPER must be configured.');
    }
    if ($otpPepper === $tokenPepper || $otpPepper === $rateLimitPepper) {
        throw new InvalidArgumentException('OTP_PEPPER must differ from TOKEN_PEPPER and RATE_LIMIT_PEPPER.');
    }

    $deliveryKey = $string('NOTIFICATION_DELIVERY_KEY', '');
    if ($deliveryKey === '' && $softSecretsAllowed) {
        // 32 zero bytes, base64 — tests/local only.
        $deliveryKey = base64_encode(str_repeat("\0", 32));
    }
    if ($deliveryKey === '') {
        throw new InvalidArgumentException('NOTIFICATION_DELIVERY_KEY must be configured.');
    }
    $deliveryKeyPrevious = $string('NOTIFICATION_DELIVERY_KEY_PREVIOUS', '');
    $deliveryKeyVersion = $int('NOTIFICATION_DELIVERY_KEY_VERSION', 1);
    if ($deliveryKeyVersion < 1) {
        throw new InvalidArgumentException('NOTIFICATION_DELIVERY_KEY_VERSION must be a positive SMALLINT.');
    }

    $emailAdapter = $string('NOTIFICATION_EMAIL_ADAPTER', '');
    $smsAdapter = $string('NOTIFICATION_SMS_ADAPTER', '');
    if ($emailAdapter === '') {
        $emailAdapter = match ($env) {
            'testing', 'ci' => 'recording',
            'local' => 'local_file',
            default => 'unavailable',
        };
    }
    if ($smsAdapter === '') {
        $smsAdapter = match ($env) {
            'testing', 'ci' => 'recording',
            default => 'unavailable',
        };
    }
    if (in_array($env, ['staging', 'production'], true)) {
        if (in_array($emailAdapter, ['recording', 'local_file'], true)) {
            throw new InvalidArgumentException('Recording/local email adapters are forbidden in staging/production.');
        }
        if ($smsAdapter === 'recording') {
            throw new InvalidArgumentException('Recording SMS adapters are forbidden in staging/production.');
        }
    }

    return [
        'trusted_proxies' => [], // populated by app.php after proxy parsing
        'force_https' => $forceHttps,
        'rate_limit_pepper' => $rateLimitPepper,
        'session' => [
            'cookie_secure' => $cookieSecure,
            'cookies' => [
                'session_name' => $sessionCookieName,
                'csrf_name' => $csrfCookieName,
            ],
            'activity_write_throttle_seconds' => $int('SESSION_ACTIVITY_THROTTLE_SECONDS', 300),
            'required_path_prefixes' => $requiredPathPrefixes,
            'timeouts' => [
                'default' => [
                    'idle_seconds' => $int('SESSION_IDLE_SECONDS', 30 * 60),
                    'absolute_seconds' => $int('SESSION_ABSOLUTE_SECONDS', 12 * 60 * 60),
                ],
                'privileged' => [
                    'idle_seconds' => $int('SESSION_PRIVILEGED_IDLE_SECONDS', 15 * 60),
                    'absolute_seconds' => $int('SESSION_PRIVILEGED_ABSOLUTE_SECONDS', 8 * 60 * 60),
                ],
            ],
        ],
        'rate_limit' => [
            'policies' => $policies,
            'path_policies' => $pathPolicies,
        ],
        'outbox' => [
            'lease_seconds' => $int('OUTBOX_LEASE_SECONDS', 60),
            'max_attempts' => $int('OUTBOX_MAX_ATTEMPTS', 10),
            'backoff_base_seconds' => $int('OUTBOX_BACKOFF_BASE_SECONDS', 5),
            'backoff_cap_seconds' => $int('OUTBOX_BACKOFF_CAP_SECONDS', 3600),
            'transport' => $string('OUTBOX_TRANSPORT', 'unconfigured'),
        ],
        'identity_tokens' => [
            'token_pepper' => $tokenPepper,
            'otp_pepper' => $otpPepper,
            'confirmation_context_ttl_seconds' => $int('TOKEN_CONFIRMATION_CONTEXT_TTL_SECONDS', 900),
            'confirmation_cleanup_retention_days' => $int('TOKEN_CONFIRMATION_CLEANUP_RETENTION_DAYS', 7),
            'confirmation_cleanup_batch_size' => $int('TOKEN_CONFIRMATION_CLEANUP_BATCH_SIZE', 1000),
            'cookie_secure' => $cookieSecure,
            'use_host_prefix' => $useHostPrefix,
        ],
        'notifications' => [
            'delivery_key' => $deliveryKey,
            'delivery_key_previous' => $deliveryKeyPrevious === '' ? null : $deliveryKeyPrevious,
            'delivery_key_version' => $deliveryKeyVersion,
            'delivery_key_previous_version' => $deliveryKeyPrevious === '' ? null : max(1, $deliveryKeyVersion - 1),
            'email_adapter' => $emailAdapter,
            'sms_adapter' => $smsAdapter,
            'local_mail_path' => $string('NOTIFICATION_LOCAL_MAIL_PATH', 'storage/mail'),
        ],
    ];
};
