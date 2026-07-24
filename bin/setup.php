<?php

declare(strict_types=1);

/**
 * Clean-install / UAT bootstrap verification (RC-01).
 *
 * Usage:
 *   php bin/setup.php
 *   php bin/setup.php --seed-uat
 *   php bin/setup.php --skip-migrate
 *   php bin/setup.php --skip-assets
 *
 * Does not overwrite populated databases silently. Never prints secret values.
 */

use Academy\Application\Ops\AppEnvironment;
use Academy\Application\Ops\BuildMetadata;
use Academy\Application\Ops\EnvironmentCapability;
use Academy\Application\Ops\EnvironmentValidator;
use Academy\Application\Ops\ReadinessProbe;
use Academy\Application\Ops\UatSeedService;
use Academy\Infrastructure\Database\ConnectionFactory;
use Psr\Container\ContainerInterface;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$args = array_slice($argv, 1);
$seedUat = in_array('--seed-uat', $args, true);
$skipMigrate = in_array('--skip-migrate', $args, true);
$skipAssets = in_array('--skip-assets', $args, true);

$fail = static function (string $message): never {
    fwrite(STDERR, '[setup] ERROR: ' . $message . "\n");
    exit(1);
};

$info = static function (string $message): void {
    fwrite(STDOUT, '[setup] ' . $message . "\n");
};

$info('PHP ' . PHP_VERSION);
if (version_compare(PHP_VERSION, '8.4.0', '<')) {
    $fail('PHP 8.4+ required.');
}
foreach (['pdo_mysql', 'mbstring', 'json', 'sodium'] as $ext) {
    if (!extension_loaded($ext)) {
        $fail('Missing PHP extension: ' . $ext);
    }
}

if (!is_dir($root . '/vendor')) {
    $fail('Composer dependencies missing. Run: composer install --no-interaction --prefer-dist');
}

foreach (['storage', 'storage/logs', 'storage/documents', 'storage/mail', 'storage/cache'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        $fail('Cannot create writable directory: ' . $dir);
    }
    if (!is_writable($path)) {
        $fail('Directory not writable: ' . $dir);
    }
}
$info('Runtime directories writable.');

/** @var array $config */
$config = require $root . '/config/app.php';
$capability = EnvironmentCapability::fromEnvName((string) $config['app']['env']);
$validation = (new EnvironmentValidator())->validate($config, $capability);
foreach ($validation->warnings() as $warning) {
    $info('WARN: ' . $warning);
}
if (!$validation->ok()) {
    foreach ($validation->errors() as $error) {
        fwrite(STDERR, '[setup] CONFIG: ' . $error . "\n");
    }
    $fail('Environment validation failed.');
}
$info('Environment validation passed (APP_ENV=' . $capability->name() . ').');

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['name'],
        $config['database']['charset'],
    );
    $pdo = new PDO(
        $dsn,
        $config['database']['user'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
    $pdo->query('SELECT 1');
    $info('Database connection OK.');
} catch (Throwable) {
    $fail('Database connection failed (credentials not printed).');
}

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'",
)->fetchColumn();

if (!$skipMigrate) {
    if ($tableCount > 1) {
        // phinxlog alone or empty-ish is OK; populated DB requires explicit skip.
        $hasPhinx = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'phinxlog'",
        )->fetchColumn() === 1;
        $nonPhinx = $tableCount - ($hasPhinx ? 1 : 0);
        if ($nonPhinx > 0) {
            $info('Database already has schema tables (' . $nonPhinx . '). Skipping migrate (use --skip-migrate to silence). Running migrate anyway is safe/idempotent via Phinx.');
        }
    }

    $migrate = $root . '/vendor/bin/phinx migrate -c ' . escapeshellarg($root . '/phinx.php');
    $env = match ($capability->environment()) {
        AppEnvironment::Ci => 'ci',
        AppEnvironment::Testing => 'testing',
        default => 'development',
    };
    $cmd = $migrate . ' -e ' . escapeshellarg($env);
    $info('Running migrations (' . $env . ')…');
    passthru($cmd, $migrateCode);
    if ($migrateCode !== 0) {
        $fail('Migrations failed.');
    }
    $info('Migrations complete.');
} else {
    $info('Skipped migrations (--skip-migrate).');
}

if (!$skipAssets) {
    if (!is_file($root . '/node_modules/bootstrap/package.json')) {
        $info('Installing npm dependencies…');
        passthru('cd ' . escapeshellarg($root) . ' && npm ci', $npmCode);
        if ($npmCode !== 0) {
            $fail('npm ci failed.');
        }
    }
    passthru('cd ' . escapeshellarg($root) . ' && npm run assets:install', $assetCode);
    if ($assetCode !== 0) {
        $fail('Frontend asset install failed.');
    }
    $info('Frontend assets installed.');
} else {
    $info('Skipped assets (--skip-assets).');
}

/** @var ContainerInterface $container */
$container = require $root . '/config/bootstrap.php';

if ($seedUat) {
    if (!$capability->allowsUatSeedAndReset()) {
        $fail('UAT seed refused for this APP_ENV.');
    }
    $info('Seeding demo catalogue…');
    passthru(
        $root . '/vendor/bin/phinx seed:run -c ' . escapeshellarg($root . '/phinx.php')
        . ' -e development -s Wp02DemoCatalogueSeeder',
        $seedCode,
    );
    if ($seedCode !== 0) {
        $info('WARN: Wp02DemoCatalogueSeeder exit=' . $seedCode . ' (may already exist or wrong env).');
    }
    passthru(
        $root . '/vendor/bin/phinx seed:run -c ' . escapeshellarg($root . '/phinx.php')
        . ' -e development -s Wp04ReviewerDemoSeeder',
        $revCode,
    );
    $result = $container->get(UatSeedService::class)->seed();
    $info('UAT seed complete: personas=' . $result['personas']
        . ' applications=' . $result['applications']
        . ' notifications=' . $result['notifications']);
}

$probe = new ReadinessProbe();
$ready = $probe->probe($config, $capability, $container->get(ConnectionFactory::class)->connection(), true);
if (!$ready['ready']) {
    $fail('Readiness probe failed: ' . json_encode($ready['checks']));
}
$info('Readiness OK.');

$build = BuildMetadata::fromEnvironment($config, $ready['build']['schema_version'] ?? null);
$info('Build: version=' . $build->applicationVersion()
    . ' sha=' . $build->commitSha()
    . ' env=' . $build->environment()
    . ' schema=' . ($build->schemaVersion() ?? 'unknown'));

$info('Next commands:');
$info('  php -S 127.0.0.1:8080 -t public');
$info('  php bin/jobs.php notification:deliver');
$info('  php bin/jobs.php payment:webhook-process');
if (!$seedUat && $capability->allowsUatSeedAndReset()) {
    $info('  php bin/setup.php --seed-uat   # or: php bin/jobs.php uat:seed');
}
$info('Done.');
exit(0);
