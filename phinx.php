<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$envName = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
$envName = is_string($envName) ? strtolower($envName) : 'local';
if (in_array($envName, ['local', 'ci', 'testing'], true) && is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$getenv = static function (string $key, string $default = ''): string {
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return (string) $value;
};

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $getenv('DB_HOST', '127.0.0.1'),
            'name' => $getenv('DB_NAME', 'academy_lms'),
            'user' => $getenv('DB_USER', 'academy'),
            'pass' => $getenv('DB_PASSWORD', ''),
            'port' => (int) $getenv('DB_PORT', '3306'),
            'charset' => $getenv('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => $getenv('DB_HOST', '127.0.0.1'),
            'name' => $getenv('DB_NAME', 'academy_lms_test'),
            'user' => $getenv('DB_USER', 'academy'),
            'pass' => $getenv('DB_PASSWORD', ''),
            'port' => (int) $getenv('DB_PORT', '3306'),
            'charset' => $getenv('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'ci' => [
            'adapter' => 'mysql',
            'host' => $getenv('DB_HOST', '127.0.0.1'),
            'name' => $getenv('DB_NAME', 'academy_lms_ci'),
            'user' => $getenv('DB_USER', 'root'),
            'pass' => $getenv('DB_PASSWORD', 'root'),
            'port' => (int) $getenv('DB_PORT', '3306'),
            'charset' => $getenv('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
];
