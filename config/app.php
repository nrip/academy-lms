<?php

declare(strict_types=1);

use Dotenv\Dotenv;

/**
 * Composition-root bootstrap. Loads dotenv only for local/CI environments.
 *
 * @return array{
 *   app: array{name: string, env: string, debug: bool, url: string},
 *   database: array{
 *     host: string,
 *     port: int,
 *     name: string,
 *     user: string,
 *     password: string,
 *     charset: string,
 *     options: array<int, mixed>
 *   },
 *   logging: array{name: string, level: string, path: string, json: bool},
 *   security: array{trusted_proxies: list<string>, force_https: bool},
 *   paths: array{root: string, templates: string, storage: string, public: string}
 * }
 */
$root = dirname(__DIR__);

$envName = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
$envName = is_string($envName) ? strtolower($envName) : 'local';

$dotenvAllowed = in_array($envName, ['local', 'ci', 'testing'], true);
if ($dotenvAllowed && is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$bool = static function (string $key, bool $default): bool {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
    if ($value === false) {
        $value = getenv($key);
    }
    if ($value === false || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
};

$string = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
    if ($value === false) {
        $value = getenv($key);
    }
    if ($value === false || $value === '') {
        return $default;
    }

    return (string) $value;
};

$env = strtolower($string('APP_ENV', 'local'));
$debug = $bool('APP_DEBUG', $env !== 'production');

$trustedProxiesRaw = $string('TRUSTED_PROXIES', '');
$trustedProxies = $trustedProxiesRaw === ''
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $trustedProxiesRaw)), static fn (string $ip): bool => $ip !== ''));

$logPath = $string('LOG_PATH', 'storage/logs/app.log');
if (!str_starts_with($logPath, '/')) {
    $logPath = $root . '/' . $logPath;
}

return [
    'app' => [
        'name' => $string('APP_NAME', 'Academy LMS'),
        'env' => $env,
        'debug' => $debug,
        'url' => $string('APP_URL', 'http://localhost:8080'),
    ],
    'database' => [
        'host' => $string('DB_HOST', '127.0.0.1'),
        'port' => (int) $string('DB_PORT', '3306'),
        'name' => $string('DB_NAME', 'academy_lms'),
        'user' => $string('DB_USER', 'academy'),
        'password' => $string('DB_PASSWORD', ''),
        'charset' => $string('DB_CHARSET', 'utf8mb4'),
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 5,
        ],
    ],
    'logging' => [
        'name' => 'academy',
        'level' => $string('LOG_LEVEL', $debug ? 'debug' : 'info'),
        'path' => $logPath,
        'json' => !in_array($env, ['local', 'testing'], true),
    ],
    'security' => [
        'trusted_proxies' => $trustedProxies,
        'force_https' => $bool('FORCE_HTTPS', $env === 'production'),
        // Deferred stores (Decision D9) — configuration keys reserved only:
        // 'session_store' => null,
        // 'rate_limit_store' => null,
    ],
    'paths' => [
        'root' => $root,
        'templates' => $root . '/templates',
        'storage' => $root . '/storage',
        'public' => $root . '/public',
    ],
];
