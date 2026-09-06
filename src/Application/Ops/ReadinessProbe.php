<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

use PDO;
use Throwable;

/**
 * Bounded readiness probe — no secrets, no raw exception messages, no heavy scans.
 */
final class ReadinessProbe
{
    private const DB_TIMEOUT_SECONDS = 3;

    /**
     * @param array{
     *   app: array{env: string, debug: bool, url: string},
     *   database: array{host: string, port: int, name: string, user: string, password: string, charset: string, options?: array<int, mixed>},
     *   logging: array{path: string},
     *   security: array<string, mixed>,
     *   paths: array{storage: string}
     * } $config
     * @return array{ready: bool, status: string, checks: array<string, string>, build?: array<string, mixed>}
     */
    public function probe(array $config, EnvironmentCapability $capability, ?PDO $pdo = null, bool $includeBuild = true): array
    {
        $checks = [];
        $ready = true;

        $validation = (new EnvironmentValidator())->validate($config, $capability);
        if (!$validation->ok()) {
            $ready = false;
            $checks['configuration'] = 'fail';
        } else {
            $checks['configuration'] = 'ok';
        }

        $db = $this->checkDatabase($config, $pdo);
        $checks['database'] = $db['status'];
        if ($db['status'] !== 'ok') {
            $ready = false;
        }

        $schemaVersion = $db['schema_version'];
        if ($db['status'] === 'ok') {
            if ($schemaVersion === null || $schemaVersion === '') {
                $checks['schema'] = 'fail';
                $ready = false;
            } else {
                $checks['schema'] = 'ok';
            }
        } else {
            $checks['schema'] = 'skip';
        }

        $storage = (string) ($config['paths']['storage'] ?? '');
        if ($storage === '' || !is_dir($storage) || !is_writable($storage)) {
            $checks['storage'] = 'fail';
            $ready = false;
        } else {
            $checks['storage'] = 'ok';
        }

        $logDir = dirname((string) ($config['logging']['path'] ?? ''));
        if ($logDir === '.' || $logDir === '' || (!is_dir($logDir) && !@mkdir($logDir, 0775, true)) || !is_writable($logDir)) {
            $checks['logging'] = 'fail';
            $ready = false;
        } else {
            $checks['logging'] = 'ok';
        }

        $adapterCheck = $this->checkRequiredAdapters($config, $capability);
        $checks['adapters'] = $adapterCheck;
        if ($adapterCheck !== 'ok') {
            $ready = false;
        }

        $payload = [
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
        ];

        if ($includeBuild) {
            $payload['build'] = BuildMetadata::fromEnvironment($config, $schemaVersion)->toArray();
        }

        return $payload;
    }

    /**
     * @param array{database: array{host: string, port: int, name: string, user: string, password: string, charset: string, options?: array<int, mixed>}} $config
     * @return array{status: string, schema_version: string|null}
     */
    private function checkDatabase(array $config, ?PDO $pdo): array
    {
        try {
            $connection = $pdo ?? $this->connect($config['database']);
            $connection->query('SELECT 1');
            $stmt = $connection->query(
                'SELECT version FROM phinxlog ORDER BY version DESC LIMIT 1',
            );
            $version = $stmt === false ? false : $stmt->fetchColumn();

            return [
                'status' => 'ok',
                'schema_version' => is_string($version) || is_numeric($version) ? (string) $version : null,
            ];
        } catch (Throwable) {
            return ['status' => 'fail', 'schema_version' => null];
        }
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, charset: string, options?: array<int, mixed>} $database
     */
    private function connect(array $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['name'],
            $database['charset'],
        );
        $options = $database['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_TIMEOUT] = self::DB_TIMEOUT_SECONDS;

        return new PDO($dsn, $database['user'], $database['password'], $options);
    }

    /**
     * @param array{security: array<string, mixed>} $config
     */
    private function checkRequiredAdapters(array $config, EnvironmentCapability $capability): string
    {
        $security = $config['security'];
        $payments = is_array($security['payments'] ?? null) ? $security['payments'] : [];
        $documents = is_array($security['documents'] ?? null) ? $security['documents'] : [];
        $notifications = is_array($security['notifications'] ?? null) ? $security['notifications'] : [];

        if ($capability->isProductionLike()) {
            if (!empty($payments['fake_gateway_enabled'])) {
                return 'fail';
            }
            if (($documents['storage_driver'] ?? '') === 'local') {
                return 'fail';
            }
            if (!empty($documents['fake_scanner_enabled'])) {
                return 'fail';
            }
            if (in_array((string) ($notifications['email_adapter'] ?? ''), ['recording', 'local_file'], true)) {
                return 'fail';
            }

            // Production-like may boot with Unconfigured* adapters; readiness still
            // reports adapters ok when fake adapters are absent (fail-on-use pattern).
            return 'ok';
        }

        // Non-production-like: require a usable storage path when local driver selected.
        if (($documents['storage_driver'] ?? '') === 'local') {
            $base = (string) ($documents['local_base_path'] ?? '');
            if ($base === '') {
                return 'fail';
            }
        }

        return 'ok';
    }
}
