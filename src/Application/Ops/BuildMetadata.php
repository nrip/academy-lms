<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

/**
 * Safe build/version metadata for CLI and readiness (RC-01).
 * Prefer environment-injected values; never expose secrets or filesystem paths.
 */
final class BuildMetadata
{
    public function __construct(
        private readonly string $applicationVersion,
        private readonly string $commitSha,
        private readonly string $buildTime,
        private readonly string $environment,
        private readonly ?string $schemaVersion,
    ) {
    }

    /**
     * @param array{app: array{env: string}} $config
     */
    public static function fromEnvironment(array $config, ?string $schemaVersion = null): self
    {
        $version = self::envString('APP_VERSION', '0.0.0-dev');
        $sha = self::envString('GIT_SHA', self::envString('GITHUB_SHA', 'unknown'));
        if (strlen($sha) > 40) {
            $sha = substr($sha, 0, 40);
        }
        $buildTime = self::envString('BUILD_TIME', '');

        return new self(
            applicationVersion: $version,
            commitSha: $sha,
            buildTime: $buildTime,
            environment: (string) ($config['app']['env'] ?? 'unknown'),
            schemaVersion: $schemaVersion,
        );
    }

    /**
     * @return array{
     *   application_version: string,
     *   commit_sha: string,
     *   build_time: string,
     *   environment: string,
     *   schema_version: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'application_version' => $this->applicationVersion,
            'commit_sha' => $this->commitSha,
            'build_time' => $this->buildTime,
            'environment' => $this->environment,
            'schema_version' => $this->schemaVersion,
        ];
    }

    public function applicationVersion(): string
    {
        return $this->applicationVersion;
    }

    public function commitSha(): string
    {
        return $this->commitSha;
    }

    public function buildTime(): string
    {
        return $this->buildTime;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function schemaVersion(): ?string
    {
        return $this->schemaVersion;
    }

    private static function envString(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }
}
