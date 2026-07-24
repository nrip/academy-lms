<?php

declare(strict_types=1);

namespace Academy\Application\Ops;

/**
 * Central environment capability policy (RC-01).
 *
 * uat is deliberately NOT production-like for fake adapters: UAT may use
 * explicit fake/local providers. staging/production always fail closed.
 */
final class EnvironmentCapability
{
    public function __construct(
        private readonly AppEnvironment $environment,
    ) {
    }

    public static function fromEnvName(string $envName): self
    {
        $parsed = AppEnvironment::tryFromMixed($envName);
        if ($parsed === null) {
            // Fail closed: unknown APP_ENV names are treated as production-like.
            return new self(AppEnvironment::Production);
        }

        return new self($parsed);
    }

    public function environment(): AppEnvironment
    {
        return $this->environment;
    }

    public function name(): string
    {
        return $this->environment->value;
    }

    /** Dotenv file may be loaded. */
    public function allowsDotenvFile(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Local,
            AppEnvironment::Testing,
            AppEnvironment::Ci,
            AppEnvironment::Uat,
        ], true);
    }

    /**
     * Soft default peppers/keys for local/CI convenience only.
     * UAT/staging/production must configure key material explicitly.
     */
    public function allowsSoftSecretDefaults(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Local,
            AppEnvironment::Testing,
            AppEnvironment::Ci,
        ], true);
    }

    /**
     * Fake payment gateway / fake scanner / local object storage / recording mail
     * may be constructed when the matching explicit flag/driver is set.
     */
    public function allowsFakeOrLocalAdapters(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Local,
            AppEnvironment::Testing,
            AppEnvironment::Ci,
            AppEnvironment::Uat,
        ], true);
    }

    /** Staging + production: no fake adapters, no UAT seed/reset. */
    public function isProductionLike(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Staging,
            AppEnvironment::Production,
        ], true);
    }

    public function allowsUatSeedAndReset(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Local,
            AppEnvironment::Testing,
            AppEnvironment::Ci,
            AppEnvironment::Uat,
        ], true);
    }

    public function allowsDebugDetails(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Local,
            AppEnvironment::Testing,
        ], true);
    }

    /** Default local_file email for local; recording for testing/ci; unavailable otherwise. */
    public function defaultEmailAdapter(): string
    {
        return match ($this->environment) {
            AppEnvironment::Testing, AppEnvironment::Ci => 'recording',
            AppEnvironment::Local => 'local_file',
            default => 'unavailable',
        };
    }

    public function defaultSmsAdapter(): string
    {
        return match ($this->environment) {
            AppEnvironment::Testing, AppEnvironment::Ci => 'recording',
            default => 'unavailable',
        };
    }

    public function defaultDocumentsStorageDriver(): string
    {
        return $this->allowsFakeOrLocalAdapters() ? 'local' : 'unconfigured';
    }

    public function defaultFakeScannerEnabled(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Testing,
            AppEnvironment::Ci,
        ], true);
    }

    public function defaultFakePaymentGatewayEnabled(): bool
    {
        return in_array($this->environment, [
            AppEnvironment::Testing,
            AppEnvironment::Ci,
        ], true);
    }

    public function defaultForceHttps(): bool
    {
        return $this->environment === AppEnvironment::Production;
    }

    public function defaultSessionCookieSecure(): bool
    {
        return $this->isProductionLike();
    }
}
