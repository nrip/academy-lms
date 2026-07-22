<?php

declare(strict_types=1);

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

putenv('APP_DEBUG=true');
$_ENV['APP_DEBUG'] = 'true';
$_SERVER['APP_DEBUG'] = 'true';

putenv('FORCE_HTTPS=false');
$_ENV['FORCE_HTTPS'] = 'false';
$_SERVER['FORCE_HTTPS'] = 'false';

putenv('LOG_PATH=' . dirname(__DIR__) . '/storage/logs/test.log');
$_ENV['LOG_PATH'] = dirname(__DIR__) . '/storage/logs/test.log';
$_SERVER['LOG_PATH'] = dirname(__DIR__) . '/storage/logs/test.log';

$softEnv = static function (string $key, string $value): void {
    $current = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($current === false || $current === null || $current === '') {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
};

// Soft secrets for testing only — must differ (OTP ≠ TOKEN).
$softEnv('TOKEN_PEPPER', 'testing-token-pepper-not-for-production');
$softEnv('OTP_PEPPER', 'testing-otp-pepper-not-for-production');
// base64_encode(str_repeat("\0", 32))
$softEnv('NOTIFICATION_DELIVERY_KEY', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
$softEnv('OUTBOX_TRANSPORT', 'memory');

require dirname(__DIR__) . '/vendor/autoload.php';
